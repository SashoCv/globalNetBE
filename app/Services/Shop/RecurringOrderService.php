<?php

namespace App\Services\Shop;

use App\Mail\RecurringOrderCancelledMail;
use App\Mail\RecurringOrderCancelRequestedMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use App\Models\ShopRecurringOrder;
use App\Models\ShopRecurringOrderItem;
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
 * monthly cutoff (19th, 15:00) passes, the current items are ordered again and
 * the order goes on that period's monthly invoice. Until the cutoff the clinic
 * can change quantities and add or remove products. Only the admin cancels.
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
     * When the current billing period's order is made: the 19th at 15:00
     * of the month the period closes in.
     */
    public static function cutoffFor(Carbon $now): Carbon
    {
        $tz = config('shop.weekly.timezone');
        [, $periodTo] = ShopInvoice::billingPeriod($now);

        return Carbon::parse($periodTo . ' ' . config('shop.recurring.cutoff_time', '15:00'), $tz);
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

        if ($now->lt($cutoff) && !$this->orderedInPeriod($recurring, $now)) {
            return $cutoff;
        }

        // This period is done: the next one closes a month later.
        return self::cutoffFor($cutoff->copy()->addDays(2));
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
     * Make this period's order for every active multi-month order whose
     * cutoff has passed. Safe to run as often as needed.
     *
     * @return array{created: int, skipped: int}
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now ?? now();

        if ($now->lt(self::cutoffFor($now))) {
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;

        ShopRecurringOrder::where('status', ShopRecurringOrder::STATUS_ACTIVE)
            ->with(['clinic', 'orderModel', 'items.product'])
            ->orderBy('id')
            ->each(function (ShopRecurringOrder $recurring) use ($now, &$created, &$skipped) {
                if ($this->orderedInPeriod($recurring, $now)) {
                    return;
                }

                try {
                    $this->orderMonth($recurring) ? $created++ : $skipped++;
                } catch (\Throwable $e) {
                    $skipped++;
                    Log::error('[recurring] month order failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
                }
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Order the next month now. Products no longer sold are left out; when
     * nothing is left the month is not ordered and the admin is told.
     */
    public function orderMonth(ShopRecurringOrder $recurring): ?ShopOrder
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

        if ($unavailable) {
            $this->tellAdmin(
                'recurring_items_unavailable',
                'Повеќемесечна нарачка: недостапни производи',
                "{$recurring->clinic?->name} — повеќемесечна нарачка #{$recurring->id}, месец {$month}: "
                    . implode(', ', $unavailable) . ($lines ? ' не се нарачани.' : '. Месецот не е нарачан.'),
                $recurring,
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
            ['shop_recurring_order_id' => $recurring->id, 'recurring_month' => $month],
        );

        if ($month >= $recurring->months_total) {
            $this->complete($recurring);
        }

        $this->placement->notify($order);

        return $order;
    }

    /**
     * Replace the items ordered from next month on. Products already on the
     * order keep their guaranteed price; new ones are guaranteed at today's.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function updateItems(ShopRecurringOrder $recurring, array $items): ShopRecurringOrder
    {
        if (!$recurring->isActive()) {
            throw ValidationException::withMessages(['items' => 'Оваа повеќемесечна нарачка повеќе не е активна.']);
        }

        $quantities = [];
        foreach ($items as $row) {
            $quantities[(int) $row['product_id']] = ($quantities[(int) $row['product_id']] ?? 0) + (int) $row['quantity'];
        }

        $products = ShopProduct::whereIn('id', array_keys($quantities))->get()->keyBy('id');

        foreach ($quantities as $productId => $qty) {
            $product = $products->get($productId);
            if (!$product || $product->status !== 'active' || $product->kind !== 'product') {
                throw ValidationException::withMessages([
                    'items' => 'Производот "' . ($product?->name ?? $productId) . '" не е достапен.',
                ]);
            }
        }

        DB::transaction(function () use ($recurring, $quantities, $products) {
            $existing = $recurring->items()->get()->keyBy('shop_product_id');

            $recurring->items()->whereNotIn('shop_product_id', array_keys($quantities))->delete();

            foreach ($quantities as $productId => $qty) {
                if ($item = $existing->get($productId)) {
                    $item->update(['quantity' => $qty]);
                } else {
                    $recurring->items()->create([
                        'shop_product_id' => $productId,
                        'quantity' => $qty,
                        'locked_price' => $products[$productId]->price,
                    ]);
                }
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

    private function orderedInPeriod(ShopRecurringOrder $recurring, Carbon $now): bool
    {
        [$from, $to] = ShopInvoice::billingPeriod($now);

        return $recurring->orders()->whereBetween('placed_at', ShopInvoice::placedBetween($from, $to))->exists();
    }

    private function tellAdmin(string $type, string $title, string $body, ShopRecurringOrder $recurring): void
    {
        try {
            AdminNotification::notify(
                type: $type,
                title: $title,
                body: $body,
                data: ['recurring_order_id' => $recurring->id],
                link: '/admin/shop-recurring-orders',
            );
        } catch (\Throwable $e) {
            Log::error('[recurring] admin notification failed', ['recurring_id' => $recurring->id, 'error' => $e->getMessage()]);
        }
    }
}
