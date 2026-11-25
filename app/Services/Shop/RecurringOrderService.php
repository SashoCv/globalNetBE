<?php

namespace App\Services\Shop;

use App\Mail\RecurringOrderCancelledMail;
use App\Mail\RecurringOrderCancelRequestedMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderModel;
use App\Models\ShopRecurringOrder;
use App\Support\ShopAdmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Multi-month orders (МОДЕЛ 04).
 *
 * The checkout order is month 1. Every billing period after that, once the
 * monthly reservations close (end of the 19th), the current items are ordered
 * again and the order goes on that period's monthly invoice. Until then the
 * clinic can change quantities. Only the admin cancels.
 */
class RecurringOrderService
{
    public function __construct(
        private readonly ShopOrderPlacement $placement,
    ) {
    }

    public static function isRecurring(?ShopOrderModel $model): bool
    {
        return $model !== null && $model->code === config('shop.recurring.model_code');
    }

    /** @return int[] */
    public static function monthOptions(): array
    {
        return array_map('intval', config('shop.recurring.month_options', [3, 6, 9, 12]));
    }

    /**
     * The last moment of the current billing period: what is saved until then
     * counts for its order.
     */
    public static function cutoffFor(Carbon $now): Carbon
    {
        $tz = config('shop.weekly.timezone');
        [, $periodTo] = ShopInvoice::billingPeriod($now);

        return Carbon::parse($periodTo, $tz)->endOfDay();
    }

    /**
     * The next time a month's order will be made for this multi-month order,
     * or null when no more months are due.
     */
    public function nextRunAt(ShopRecurringOrder $recurring, ?Carbon $now = null): ?Carbon
    {
        if (!$recurring->isActive() || $recurring->monthsOrdered() >= $recurring->months_total) {
            return null;
        }

        $now = $now ?? now();
        $cutoff = self::cutoffFor($now);

        if (!$this->orderedInPeriod($recurring, ShopInvoice::billingPeriod($now))) {
            return $cutoff;
        }

        // This period is done: the next one closes a month later.
        return self::cutoffFor($cutoff->copy()->addDay());
    }

    /**
     * Turn a just-placed checkout order into month 1 of a multi-month order.
     */
    public function start(ShopOrder $order, int $months): ShopRecurringOrder
    {
        return DB::transaction(function () use ($order, $months) {
            $recurring = ShopRecurringOrder::create([
                'shop_clinic_id' => $order->shop_clinic_id,
                'shop_order_model_id' => $order->shop_order_model_id,
                'months_total' => $months,
                'status' => ShopRecurringOrder::STATUS_ACTIVE,
                'delivery_contact' => $order->delivery_contact,
                'delivery_phone' => $order->delivery_phone,
                'delivery_email' => $order->delivery_email,
                'delivery_city' => $order->delivery_city,
                'delivery_address' => $order->delivery_address,
                'delivery_notes' => $order->delivery_notes,
            ]);

            foreach ($order->items()->where('kind', 'product')->get() as $item) {
                $recurring->items()->create([
                    'shop_product_id' => $item->shop_product_id,
                    'quantity' => $item->quantity,
                    'locked_price' => $item->unit_sale_price,
                ]);
            }

            $order->update(['shop_recurring_order_id' => $recurring->id, 'recurring_month' => 1]);

            return $recurring;
        });
    }

    /**
     * Make the order of the billing period that just closed, for every active
     * multi-month order that has none in it. Safe to run as often as needed; a
     * missed run catches up until the period is invoiced.
     *
     * @return array{created: int, skipped: int}
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now ?? now();

        $period = ShopInvoice::lastClosedBillingPeriod($now);

        if ($now->greaterThanOrEqualTo(MonthlySchedule::forPeriodEnd($period[1])->proformaAt)) {
            return ['created' => 0, 'skipped' => 0];
        }

        $closedAt = Carbon::parse($period[1], config('shop.weekly.timezone'))->endOfDay()->utc()->startOfSecond();

        $created = 0;
        $skipped = 0;

        ShopRecurringOrder::where('status', ShopRecurringOrder::STATUS_ACTIVE)
            // One started after the period closed has its month 1 in the next.
            ->where('created_at', '<=', $closedAt)
            ->with(['clinic', 'orderModel', 'items.product'])
            ->orderBy('id')
            ->each(function (ShopRecurringOrder $recurring) use ($period, $closedAt, &$created, &$skipped) {
                if ($this->orderedInPeriod($recurring, $period)) {
                    return;
                }

                try {
                    $this->orderMonth($recurring, $closedAt) ? $created++ : $skipped++;
                } catch (\Throwable $e) {
                    $skipped++;
                    Log::error('[recurring] month order failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
                }
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Order the next month, as of $placedAt. Products no longer sold are left out; when
     * nothing is left the month is not ordered and the admin is told.
     */
    public function orderMonth(ShopRecurringOrder $recurring, ?Carbon $placedAt = null): ?ShopOrder
    {
        $month = $recurring->monthsOrdered() + 1;

        if ($month > $recurring->months_total) {
            $this->complete($recurring);

            return null;
        }

        $lines = [];
        $unavailable = [];

        foreach ($recurring->items as $item) {
            $product = $item->product;

            if (!$product || $product->status !== 'active' || $product->kind !== 'product') {
                $unavailable[] = $product?->name ?? ('#' . $item->shop_product_id);
                continue;
            }

            $lines[] = ['product' => $product, 'qty' => $item->quantity, 'unit_sale' => $item->unitPrice()];
        }

        // Told once a month, however many times the run is repeated.
        if ($unavailable && !$this->toldUnavailable($recurring, $month)) {
            $this->tellAdmin(
                'recurring_items_unavailable',
                'Повеќемесечна нарачка: недостапни производи',
                "{$recurring->clinic?->name} — повеќемесечна нарачка #{$recurring->id}, месец {$month}: "
                    . implode(', ', $unavailable) . ($lines ? ' не се нарачани.' : '. Месецот не е нарачан.'),
                $recurring,
                ['month' => $month],
            );
        }

        if (!$lines) {
            return null;
        }

        $order = $this->placement->place(
            $recurring->clinic,
            $recurring->orderModel,
            $lines,
            $recurring->only([
                'delivery_contact', 'delivery_phone', 'delivery_email',
                'delivery_city', 'delivery_address', 'delivery_notes',
            ]),
            [
                'shop_recurring_order_id' => $recurring->id,
                'recurring_month' => $month,
                'placed_at' => $placedAt ?? now(),
            ],
        );

        if ($month >= $recurring->months_total) {
            $this->complete($recurring);
        }

        $this->placement->notify($order);

        return $order;
    }

    /**
     * Change how much of each product is ordered from next month on. Which
     * products are ordered does not change (Article 7, Model 04).
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function updateQuantities(ShopRecurringOrder $recurring, array $items): ShopRecurringOrder
    {
        if (!$recurring->isActive()) {
            throw ValidationException::withMessages(['items' => 'Оваа повеќемесечна нарачка повеќе не е активна.']);
        }

        $existing = $recurring->items()->get()->keyBy('shop_product_id');

        foreach ($items as $row) {
            if (!$existing->has((int) $row['product_id'])) {
                throw ValidationException::withMessages([
                    'items' => 'Кај повеќемесечна нарачка може да се менува само количината.',
                ]);
            }
        }

        DB::transaction(function () use ($existing, $items) {
            foreach ($items as $row) {
                $existing[(int) $row['product_id']]->update(['quantity' => (int) $row['quantity']]);
            }
        });

        return $recurring->fresh(['items.product']);
    }

    public function requestCancellation(ShopRecurringOrder $recurring, ?string $note): ShopRecurringOrder
    {
        if (!$recurring->isActive()) {
            throw ValidationException::withMessages(['note' => 'Оваа повеќемесечна нарачка повеќе не е активна.']);
        }

        $recurring->update(['cancel_requested_at' => now(), 'cancel_request_note' => $note]);

        $this->tellAdmin(
            'recurring_cancel_request',
            'Барање за откажување на повеќемесечна нарачка',
            "{$recurring->clinic?->name} бара откажување на повеќемесечна нарачка #{$recurring->id}." . ($note ? " Причина: {$note}" : ''),
            $recurring,
        );

        $adminEmail = ShopAdmin::emails();
        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new RecurringOrderCancelRequestedMail($recurring));
            } catch (\Throwable $e) {
                Log::error('[recurring] cancel request mail failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
            }
        }

        return $recurring;
    }

    public function declineCancellation(ShopRecurringOrder $recurring): ShopRecurringOrder
    {
        $recurring->update(['cancel_requested_at' => null, 'cancel_request_note' => null]);

        return $recurring;
    }

    /**
     * Stop ordering further months. Months already ordered are not touched;
     * they are cancelled one by one like any other order if needed.
     */
    public function cancel(ShopRecurringOrder $recurring, ?string $reason): ShopRecurringOrder
    {
        if (!$recurring->isActive()) {
            return $recurring;
        }

        $recurring->update([
            'status' => ShopRecurringOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        if ($recurring->clinic?->email) {
            try {
                Mail::to($recurring->clinic->email)->send(new RecurringOrderCancelledMail($recurring));
            } catch (\Throwable $e) {
                Log::error('[recurring] cancelled mail failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
            }
        }

        return $recurring;
    }

    private function complete(ShopRecurringOrder $recurring): void
    {
        $recurring->update(['status' => ShopRecurringOrder::STATUS_COMPLETED, 'completed_at' => now()]);
    }

    private function toldUnavailable(ShopRecurringOrder $recurring, int $month): bool
    {
        return AdminNotification::where('type', 'recurring_items_unavailable')
            ->where('data->recurring_order_id', $recurring->id)
            ->where('data->month', $month)
            ->where('created_at', '>=', now()->subDays(10))
            ->exists();
    }

    /** @param  array{0: string, 1: string}  $period */
    private function orderedInPeriod(ShopRecurringOrder $recurring, array $period): bool
    {
        [$from, $to] = $period;

        return $recurring->orders()->whereBetween('placed_at', ShopInvoice::placedBetween($from, $to))->exists();
    }

    private function tellAdmin(string $type, string $title, string $body, ShopRecurringOrder $recurring, array $data = []): void
    {
        try {
            AdminNotification::notify(
                type: $type,
                title: $title,
                body: $body,
                data: ['recurring_order_id' => $recurring->id] + $data,
                link: '/admin/shop-recurring-orders',
            );
        } catch (\Throwable $e) {
            Log::error('[recurring] admin notification failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
        }
    }
}
