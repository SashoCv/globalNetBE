<?php

namespace App\Services\Shop;

use App\Models\AdminNotification;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Support\WorkingDays;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The monthly group order (МОДЕЛ 03 and 04), Article 7:
 *
 *   to the 19th     clinics reserve
 *   20th 09:00      every vendor gets one order with the total quantities
 *   21st 12:00      one invoice per clinic
 *   working day before the 25th   reminder to clinics that still owe
 *   26th 08:00      the admin gets what to pay each vendor by 12:00
 *
 * Dates move for non-working days, see MonthlySchedule. tick() is safe to run
 * as often as wanted: every step leaves a mark and has a window, so a late run
 * catches up but an old month is never sent again.
 */
class MonthlyCycleService
{
    public function __construct(
        private readonly RecurringOrderService $recurring,
        private readonly VendorDispatch $vendors,
        private readonly InvoiceMailer $mailer,
    ) {
    }

    /**
     * @return array<int, string> what was done, for the console
     */
    public function tick(?Carbon $at = null): array
    {
        $tz = config('shop.weekly.timezone');
        $now = ($at ?? now())->copy()->setTimezone($tz);
        [$from, $to] = ShopInvoice::lastClosedBillingPeriod($now);
        $schedule = MonthlySchedule::forPeriodEnd($to);
        $done = [];

        if ($now->betweenIncluded($this->atTime($schedule->consolidateAt, 'consolidate_from'), $schedule->proformaAt)) {
            // The month's multi-month orders belong in it.
            $this->recurring->runDue($now);

            if ($count = $this->consolidate($from, $to, $schedule)) {
                $done[] = "{$from} → {$to}: збирна нарачка до {$count} добавувач(и)";
            }
        }

        if ($now->greaterThanOrEqualTo($schedule->proformaAt) && $now->lessThan($schedule->proformaAt->copy()->addDay())) {
            if ($count = $this->invoice($from, $to, $schedule)) {
                $done[] = "{$from} → {$to}: {$count} фактура(и)";
            }
        }

        $reminderAt = $this->reminderAt($schedule);
        if ($now->toDateString() === $reminderAt->toDateString() && $now->greaterThanOrEqualTo($reminderAt)) {
            if ($count = $this->remind($from, $to)) {
                $done[] = "{$from} → {$to}: потсетник за {$count} фактура(и)";
            }
        }

        if ($now->toDateString() === $schedule->vendorPayAt->toDateString() && $now->hour >= 8) {
            if ($this->reportPayables($from, $to, $schedule)) {
                $done[] = "{$from} → {$to}: список за плаќање кон добавувачи";
            }
        }

        return $done;
    }

    private function consolidate(string $from, string $to, MonthlySchedule $schedule): int
    {
        $orders = $this->orders($from, $to);

        if ($orders->isEmpty()) {
            return 0;
        }

        return $this->vendors->sendConsolidated(
            $orders,
            'monthly:' . $to,
            "Месечната збирна нарачка ({$this->date($from)} – {$this->date($to)})",
            "Месечна збирна нарачка за период {$this->date($from)} – {$this->date($to)}. "
                . "Ве молиме потврдете ја и доставете про-фактура до {$schedule->proformaAt->format('d.m.Y')} во {$schedule->proformaAt->format('H:i')}. "
                . 'Се плаќаат и испорачуваат резервациите што ординациите ќе ги уплатат.',
            $from,
            $to,
        );
    }

    private function invoice(string $from, string $to, MonthlySchedule $schedule): int
    {
        // Once per period: an invoice the admin cancels is not made again.
        if (ShopInvoice::whereDate('period_from', $from)->whereDate('period_to', $to)->exists()) {
            return 0;
        }

        $result = ShopInvoice::generateForPeriod($from, $to, null, $schedule->paymentDue->toDateString());

        foreach ($result['invoices'] as $invoice) {
            $this->mailer->issued($invoice);
        }

        return $result['invoices']->count();
    }

    private function remind(string $from, string $to): int
    {
        $invoices = ShopInvoice::whereDate('period_from', $from)
            ->whereDate('period_to', $to)
            ->where('status', 'pending')
            ->where('total', '>', 0)
            ->whereNull('reminder_sent_at')
            ->get();

        foreach ($invoices as $invoice) {
            $invoice->update(['reminder_sent_at' => now()]);
            $this->mailer->reminder($invoice);
        }

        return $invoices->count();
    }

    private function reportPayables(string $from, string $to, MonthlySchedule $schedule): bool
    {
        if (AdminNotification::where('type', 'monthly_vendor_payables')->where('data->period_to', $to)->exists()) {
            return false;
        }

        $orders = $this->orders($from, $to)->filter(fn (ShopOrder $o) => $o->isPaid());
        $payables = $this->vendors->payables($orders);

        if (! $payables) {
            return false;
        }

        $deadline = $schedule->vendorPayAt->format('d.m.Y') . ' во ' . $schedule->vendorPayAt->format('H:i');
        $title = "Месечна збирна нарачка {$this->date($from)} – {$this->date($to)}";

        try {
            AdminNotification::notify(
                type: 'monthly_vendor_payables',
                title: 'За плаќање кон добавувачи',
                body: sprintf(
                    '%s: %s MKD кон %d добавувач(и), до %s. Само платените нарачки.',
                    $title,
                    number_format(array_sum(array_column($payables, 'total')), 2),
                    count($payables),
                    $deadline,
                ),
                data: ['period_from' => $from, 'period_to' => $to],
                link: "/admin/shop-procurement?status=paid&from={$from}&to={$to}",
            );
        } catch (\Throwable $e) {
            // Without the mark the mail would go out on every run.
            Log::error('[monthly] payables notification failed', ['period_to' => $to, 'error' => $e->getMessage()]);

            return false;
        }

        $this->vendors->mailPayables($title, $deadline, $payables);

        return true;
    }

    /**
     * Orders of the period: everything that is not prepaid on its own invoice.
     *
     * @return Collection<int, ShopOrder>
     */
    private function orders(string $from, string $to): Collection
    {
        return ShopOrder::with(['invoice', 'orderModel'])
            ->whereNull('shop_order_cycle_id')
            ->where('status', '!=', 'cancelled')
            ->whereHas('items', fn ($q) => $q->where('kind', 'product'))
            ->whereBetween('placed_at', ShopInvoice::placedBetween($from, $to))
            ->get()
            ->reject(fn (ShopOrder $o) => ShopOrderSettlement::invoicedAtPlacement($o->orderModel))
            ->values();
    }

    // The last working day before the payment deadline.
    private function reminderAt(MonthlySchedule $schedule): Carbon
    {
        $day = $schedule->paymentDue->copy()->subDay();
        while (! WorkingDays::isWorking($day)) {
            $day->subDay();
        }

        return $this->atTime($day, 'reminder_time');
    }

    private function atTime(Carbon $day, string $key): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', config("shop.monthly.{$key}")));

        return $day->copy()->setTime($h, $m);
    }

    private function date(string $ymd): string
    {
        return Carbon::parse($ymd)->format('d.m.Y');
    }
}
