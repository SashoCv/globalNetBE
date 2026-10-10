<?php

namespace App\Services\Shop;

use App\Mail\WeeklyCycleClosedAdminMail;
use App\Mail\WeeklyOrderCancelledMail;
use App\Mail\WeeklyOrderPaymentMail;
use App\Models\AdminNotification;
use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Models\ShopOrderModel;
use App\Models\ShopPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * The weekly group-order model (Неделни збирни нарачки):
 *
 *   until Mon 12:00  clinics order; every order joins the week's cycle
 *   Mon 12:00        the cycle closes, the admin is asked for the group discount
 *   by Tue 08:00     the admin approves the extra % (or 0% is applied automatically),
 *                    totals drop by it and the clinics are asked to pay
 *   Tue 12:00        reminder to clinics that still owe
 *   Tue 14:00        payment closes: paid orders are confirmed, unpaid ones cancelled
 *   from Wed         delivery, within 5 working days
 *
 * tick() moves every cycle forward and is safe to run as often as wanted.
 */
class WeeklyCycleService
{
    private const DAY_NAMES = [
        1 => 'понеделник', 2 => 'вторник', 3 => 'среда', 4 => 'четврток',
        5 => 'петок', 6 => 'сабота', 7 => 'недела',
    ];

    public function __construct(private readonly ShopOrderCancellation $cancellation)
    {
    }

    public static function isWeekly(?ShopOrderModel $model): bool
    {
        return $model !== null && $model->code === config('shop.weekly.model_code');
    }

    /**
     * Deadlines of the cycle that takes an order placed at $at. An order at
     * exactly the cutoff already belongs to the following week.
     *
     * @return array<string, mixed>
     */
    public function windowFor(Carbon $at): array
    {
        $cfg = config('shop.weekly');
        $tz = $cfg['timezone'];
        $local = $at->copy()->setTimezone($tz);

        $cutoff = $this->at($local->copy()->startOfWeek(Carbon::MONDAY), $cfg['cutoff_day'], $cfg['cutoff_time']);
        if ($local->greaterThanOrEqualTo($cutoff)) {
            $cutoff->addWeek();
        }

        $base = $cutoff->copy()->startOfDay();
        $discountDue = $this->after($base, $cfg['cutoff_day'], $cfg['discount_day'], $cfg['discount_time']);
        $reminder = $this->after($base, $cfg['cutoff_day'], $cfg['payment_day'], $cfg['reminder_time']);
        $paymentDue = $this->after($base, $cfg['cutoff_day'], $cfg['payment_day'], $cfg['payment_time']);
        $deliveryFrom = $this->after($base, $cfg['cutoff_day'], $cfg['delivery_day'], '00:00');
        while ($deliveryFrom->isWeekend()) {
            $deliveryFrom->addDay();
        }
        $deliveryTo = $this->addWorkingDays($deliveryFrom, max(1, (int) $cfg['delivery_working_days']) - 1);

        return [
            'code' => $cutoff->format('o-\WW'),
            'opens_at' => $cutoff->copy()->subWeek()->utc(),
            'cutoff_at' => $cutoff->copy()->utc(),
            'discount_due_at' => $discountDue->utc(),
            'reminder_at' => $reminder->utc(),
            'payment_due_at' => $paymentDue->utc(),
            'delivery_from' => $deliveryFrom->toDateString(),
            'delivery_to' => $deliveryTo->toDateString(),
        ];
    }

    /**
     * The open cycle a new order joins, created on first use.
     */
    public function currentOpenCycle(ShopOrderModel $model, ?Carbon $at = null): ShopOrderCycle
    {
        $window = $this->windowFor($at ?? now());

        $existing = ShopOrderCycle::where('code', $window['code'])->first();
        if ($existing) {
            // Only possible if the clock or the data was moved by hand — an
            // order must never join a week that is already being billed.
            if ($existing->status !== ShopOrderCycle::STATUS_OPEN) {
                throw new RuntimeException('Неделната збирна нарачка за оваа недела е веќе затворена. Обидете се повторно за неколку минути.');
            }

            return $existing;
        }

        try {
            return ShopOrderCycle::create($window + [
                'shop_order_model_id' => $model->id,
                'status' => ShopOrderCycle::STATUS_OPEN,
            ]);
        } catch (QueryException $e) {
            // Two orders created the same cycle at once — use the winner's row.
            return ShopOrderCycle::where('code', $window['code'])->firstOrFail();
        }
    }

    /**
     * Advance every cycle whose next deadline has passed.
     *
     * @return array<int, string> what was done, for the console
     */
    public function tick(?Carbon $at = null): array
    {
        $at ??= now();
        $done = [];

        foreach (ShopOrderCycle::where('status', ShopOrderCycle::STATUS_OPEN)->where('cutoff_at', '<=', $at)->get() as $cycle) {
            $this->close($cycle, $at);
            $done[] = "{$cycle->code}: затворен за нарачки";
        }

        foreach (ShopOrderCycle::where('status', ShopOrderCycle::STATUS_CLOSED)->where('discount_due_at', '<=', $at)->get() as $cycle) {
            $this->approveDiscount($cycle, 0, null, $at, auto: true);
            $done[] = "{$cycle->code}: плаќањето е отворено без дополнителен попуст (автоматски)";
        }

        $paymentOpen = ShopOrderCycle::where('status', ShopOrderCycle::STATUS_PAYMENT_OPEN)->get();

        foreach ($paymentOpen as $cycle) {
            if (! $cycle->reminder_sent_at && $cycle->reminder_at <= $at && $cycle->payment_due_at > $at) {
                $sent = $this->sendReminders($cycle, $at);
                $done[] = "{$cycle->code}: потсетник до {$sent} ординации";
            }
        }

        foreach ($paymentOpen as $cycle) {
            if ($cycle->payment_due_at > $at) {
                continue;
            }
            // Payment opened only after its own deadline (the scheduler was
            // down): cancelling now would give clinics no chance to pay, so the
            // admin closes this one by hand.
            if ($cycle->discount_approved_at && $cycle->discount_approved_at >= $cycle->payment_due_at) {
                continue;
            }
            $result = $this->closePayment($cycle, $at);
            $done[] = "{$cycle->code}: {$result['confirmed']} потврдени, {$result['cancelled']} откажани"
                . ($result['waiting'] ? ", {$result['waiting']} чекаат одговор од cPay" : '');
        }

        return $done;
    }

    /**
     * Mon 12:00 — no more orders; ask the admin for the group discount.
     */
    public function close(ShopOrderCycle $cycle, ?Carbon $at = null): void
    {
        $cycle->update([
            'status' => ShopOrderCycle::STATUS_CLOSED,
            'closed_at' => $at ?? now(),
        ]);

        $stats = $this->stats($cycle);

        $this->safely('close notification', $cycle, function () use ($cycle, $stats) {
            AdminNotification::notify(
                type: 'weekly_cycle_closed',
                title: 'Неделна збирна нарачка ' . $cycle->code . ' е затворена',
                body: sprintf(
                    '%d нарачки од %d ординации во вредност од %s MKD. Внесете збирен попуст до %s.',
                    $stats['orders_count'],
                    $stats['clinics_count'],
                    number_format($stats['subtotal'], 2),
                    $this->formatLocal($cycle->discount_due_at),
                ),
                data: ['cycle_id' => $cycle->id, 'code' => $cycle->code],
                link: '/admin/shop-weekly',
            );
        });

        $adminEmail = config('app.shop_admin_email');
        if ($adminEmail && $stats['orders_count'] > 0) {
            $this->safely('close admin mail', $cycle, fn () => Mail::to($adminEmail)->send(new WeeklyCycleClosedAdminMail($cycle, $stats)));
        }
    }

    /**
     * Take the group discount off every order of a closed cycle and open
     * payment. The % is locked from here on.
     */
    public function approveDiscount(ShopOrderCycle $cycle, float $percent, ?int $userId = null, ?Carbon $at = null, bool $auto = false): ShopOrderCycle
    {
        $at ??= now();
        $max = (float) config('shop.weekly.max_extra_discount_percent');

        if ($percent < 0 || $percent > $max) {
            throw new RuntimeException("Попустот мора да биде помеѓу 0% и {$max}%.");
        }

        $orders = DB::transaction(function () use ($cycle, $percent, $userId, $at, $auto) {
            $cycle = ShopOrderCycle::lockForUpdate()->findOrFail($cycle->id);

            if ($cycle->status !== ShopOrderCycle::STATUS_CLOSED) {
                throw new RuntimeException('Попустот за овој циклус е веќе одобрен.');
            }

            $orders = ShopOrder::with('clinic')
                ->where('shop_order_cycle_id', $cycle->id)
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'awaiting_discount')
                ->get();

            foreach ($orders as $order) {
                $this->applyDiscount($order, $percent, $cycle);
            }

            $cycle->update([
                'status' => ShopOrderCycle::STATUS_PAYMENT_OPEN,
                'extra_discount_percent' => $percent,
                'discount_approved_at' => $at,
                'discount_approved_by' => $userId,
                'auto_approved' => $auto,
            ]);

            return $orders;
        });

        $cycle->refresh();

        foreach ($orders as $order) {
            $order->refresh();
            if ($order->payment_status === 'paid') {
                // Fully covered by wallet credit — nothing left to pay.
                $this->orderPaid($order);
            }
            if ($order->clinic?->email) {
                $this->safely('payment mail', $cycle, fn () => Mail::to($order->clinic->email)->send(new WeeklyOrderPaymentMail($order, $cycle)));
            }
        }

        if ($auto) {
            $late = $at >= $cycle->payment_due_at;
            $this->safely('auto-approve notification', $cycle, fn () => AdminNotification::notify(
                type: 'weekly_cycle_closed',
                title: 'Збирниот попуст за ' . $cycle->code . ' не беше внесен',
                body: $late
                    ? 'Плаќањето е отворено по крајниот рок, па нарачките нема да се откажат автоматски. Затворете го плаќањето рачно.'
                    : 'Плаќањето е отворено без дополнителен попуст (0%). Ординациите добија е-пошта со износот.',
                data: ['cycle_id' => $cycle->id, 'code' => $cycle->code],
                link: '/admin/shop-weekly',
            ));
        }

        return $cycle;
    }

    /**
     * New total of one order after the group discount. Wallet credit already
     * spent on the order can now exceed what it costs; the excess goes back.
     */
    private function applyDiscount(ShopOrder $order, float $percent, ShopOrderCycle $cycle): void
    {
        $subtotal = (float) $order->subtotal;
        $discount = round($subtotal * $percent / 100, 2);
        $gross = max(0.0, round($subtotal - $discount + (float) $order->surcharge_amount, 2));

        $wallet = (float) $order->wallet_applied;
        if ($wallet > $gross) {
            $refund = round($wallet - $gross, 2);
            $wallet = $gross;
            $clinic = $order->clinic;
            if ($clinic && $refund > 0) {
                $newBalance = (float) $clinic->wallet_balance + $refund;
                $clinic->update(['wallet_balance' => $newBalance]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id'  => $order->id,
                    'type'           => 'credit',
                    'amount'         => $refund,
                    'balance_after'  => round($newBalance, 2),
                    'description'    => "Враќање поени по збирен попуст — нарачка #{$order->order_number}",
                ]);
            }
        }

        $total = max(0.0, round($gross - $wallet, 2));

        $order->update([
            'discount_percent' => $percent,
            'discount_amount' => $discount,
            'wallet_applied' => $wallet,
            'total' => $total,
            'payment_status' => $total > 0 ? 'pending' : 'paid',
            'payment_due_at' => $cycle->payment_due_at,
        ]);
    }

    /**
     * A weekly order was paid (card, bank transfer marked by the admin, or
     * nothing left to pay): it is confirmed and earns the starter credit.
     */
    public function orderPaid(ShopOrder $order): void
    {
        if (! $order->shop_order_cycle_id || $order->status !== 'reserved') {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'confirmed']);

            $credit = round((float) $order->total * (float) config('shop.weekly.loyalty_percent') / 100, 2);
            $clinic = $order->clinic;

            if ($credit > 0 && $clinic && (float) $order->loyalty_credit_earned <= 0) {
                $newBalance = (float) $clinic->wallet_balance + $credit;
                $clinic->update(['wallet_balance' => $newBalance]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id'  => $order->id,
                    'type'           => 'credit',
                    'amount'         => $credit,
                    'balance_after'  => round($newBalance, 2),
                    'description'    => config('shop.weekly.loyalty_percent') . "% кредит за наредна нарачка (нарачка #{$order->order_number})",
                ]);
                $order->update(['loyalty_credit_earned' => $credit]);
            }
        });
    }

    /**
     * Tue 12:00 — remind clinics that still have an unpaid order.
     */
    public function sendReminders(ShopOrderCycle $cycle, ?Carbon $at = null): int
    {
        $cycle->update(['reminder_sent_at' => $at ?? now()]);

        $orders = ShopOrder::with('clinic')
            ->where('shop_order_cycle_id', $cycle->id)
            ->where('status', 'reserved')
            ->where('payment_status', 'pending')
            ->get();

        foreach ($orders as $order) {
            if ($order->clinic?->email) {
                $this->safely('reminder mail', $cycle, fn () => Mail::to($order->clinic->email)->send(new WeeklyOrderPaymentMail($order, $cycle, reminder: true)));
            }
        }

        return $orders->count();
    }

    /**
     * Tue 14:00 — confirm what was paid, cancel what was not. An order whose
     * card payment is still with cPay gets the grace period before it goes.
     *
     * @return array{confirmed: int, cancelled: int, waiting: int}
     */
    public function closePayment(ShopOrderCycle $cycle, ?Carbon $at = null): array
    {
        $at ??= now();
        $graceStart = $at->copy()->subMinutes((int) config('shop.weekly.payment_grace_minutes'));
        $result = ['confirmed' => 0, 'cancelled' => 0, 'waiting' => 0];

        $orders = ShopOrder::with('clinic')
            ->where('shop_order_cycle_id', $cycle->id)
            ->where('status', 'reserved')
            ->get();

        foreach ($orders as $order) {
            if ($order->payment_status === 'paid') {
                $this->orderPaid($order);
                $result['confirmed']++;
                continue;
            }

            $inFlight = ShopPayment::where('payable_type', $order->getMorphClass())
                ->where('payable_id', $order->id)
                ->where('status', ShopPayment::STATUS_REDIRECTED)
                ->where('redirected_at', '>', $graceStart)
                ->exists();

            if ($inFlight) {
                $result['waiting']++;
                continue;
            }

            $this->cancellation->cancel($order, 'Автоматски откажана — не е платена до ' . $this->formatLocal($cycle->payment_due_at) . '.');
            $result['cancelled']++;

            if ($order->clinic?->email) {
                $order->refresh();
                $this->safely('cancel mail', $cycle, fn () => Mail::to($order->clinic->email)->send(new WeeklyOrderCancelledMail($order, $cycle)));
            }
        }

        if ($result['waiting'] === 0) {
            $cycle->update([
                'status' => ShopOrderCycle::STATUS_PAYMENT_CLOSED,
                'payment_closed_at' => $at,
            ]);

            $stats = $this->stats($cycle);
            $this->safely('payment closed notification', $cycle, fn () => AdminNotification::notify(
                type: 'weekly_cycle_payment_closed',
                title: 'Плаќањето за ' . $cycle->code . ' е затворено',
                body: sprintf(
                    '%d платени нарачки во вредност од %s MKD, %d откажани поради неплаќање. Испратете ја набавката до добавувачите.',
                    $stats['paid_count'],
                    number_format($stats['paid_total'], 2),
                    $stats['cancelled_count'],
                ),
                data: ['cycle_id' => $cycle->id, 'code' => $cycle->code],
                link: '/admin/shop-weekly',
            ));
        }

        return $result;
    }

    /**
     * Totals the admin screen and the notifications show for a cycle.
     *
     * @return array<string, int|float>
     */
    public function stats(ShopOrderCycle $cycle): array
    {
        $orders = ShopOrder::where('shop_order_cycle_id', $cycle->id)
            ->get(['id', 'shop_clinic_id', 'status', 'payment_status', 'subtotal', 'discount_amount', 'total']);

        $active = $orders->where('status', '!=', 'cancelled');
        $paid = $active->where('payment_status', 'paid');

        return [
            'orders_count' => $active->count(),
            'clinics_count' => $active->pluck('shop_clinic_id')->unique()->count(),
            'subtotal' => round((float) $active->sum('subtotal'), 2),
            'discount_amount' => round((float) $active->sum('discount_amount'), 2),
            'total' => round((float) $active->sum('total'), 2),
            'paid_count' => $paid->count(),
            'paid_total' => round((float) $paid->sum('total'), 2),
            'unpaid_count' => $active->where('payment_status', '!=', 'paid')->count(),
            'cancelled_count' => $orders->where('status', 'cancelled')->count(),
        ];
    }

    /**
     * "вторник, 13.10. во 14:00" in the cycle's timezone.
     */
    public function formatLocal(?Carbon $moment): string
    {
        if (! $moment) {
            return '';
        }
        $local = $moment->copy()->setTimezone(config('shop.weekly.timezone'));

        return self::DAY_NAMES[$local->isoWeekday()] . ', ' . $local->format('d.m.') . ' во ' . $local->format('H:i');
    }

    public function formatDate(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::DAY_NAMES[$d->isoWeekday()] . ', ' . $d->format('d.m.Y');
    }

    private function at(Carbon $weekStart, int $isoDay, string $time): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $weekStart->copy()->addDays($isoDay - 1)->setTime($h, $m);
    }

    // The first $isoDay on or after the cutoff day, at $time.
    private function after(Carbon $cutoffDay, int $cutoffIsoDay, int $isoDay, string $time): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $cutoffDay->copy()->addDays(($isoDay - $cutoffIsoDay + 7) % 7)->setTime($h, $m);
    }

    private function addWorkingDays(Carbon $from, int $days): Carbon
    {
        $d = $from->copy();
        while ($days > 0) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $days--;
            }
        }

        return $d;
    }

    // Mail and notifications must never stop a cycle from moving on.
    private function safely(string $what, ShopOrderCycle $cycle, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("[weekly] {$what} failed", ['cycle' => $cycle->code, 'error' => $e->getMessage()]);
        }
    }
}
