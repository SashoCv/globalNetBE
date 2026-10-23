<?php

namespace App\Http\Controllers\Api;

use App\Models\ShopRecurringOrder;
use App\Models\ShopRecurringOrderItem;
use App\Services\Shop\RecurringOrderService;

/**
 * The JSON shape of a multi-month order, shared by the clinic and admin APIs.
 */
final class RecurringOrderPresenter
{
    public static function present(ShopRecurringOrder $recurring, RecurringOrderService $service): array
    {
        $items = $recurring->items->map(fn (ShopRecurringOrderItem $item) => [
            'id' => $item->id,
            'product_id' => $item->shop_product_id,
            'quantity' => $item->quantity,
            'locked_price' => (float) $item->locked_price,
            'current_price' => $item->product ? (float) $item->product->price : null,
            'unit_price' => $item->unitPrice(),
            'available' => $item->product && $item->product->status === 'active' && $item->product->kind === 'product',
            'product' => $item->product?->only(['id', 'name', 'slug', 'sku', 'image', 'price', 'status']),
        ])->values();

        return [
            'id' => $recurring->id,
            'status' => $recurring->status,
            'months_total' => $recurring->months_total,
            'months_ordered' => $recurring->monthsOrdered(),
            'next_run_at' => $service->nextRunAt($recurring)?->toIso8601String(),
            'monthly_total' => round($items->sum(fn ($i) => $i['available'] ? $i['unit_price'] * $i['quantity'] : 0), 2),
            'items' => $items,
            'orders' => $recurring->orders->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'recurring_month' => $o->recurring_month,
                'status' => $o->status,
                'total' => (float) $o->total,
                'placed_at' => $o->placed_at?->toIso8601String(),
            ])->values(),
            'delivery_address' => $recurring->delivery_address,
            'delivery_city' => $recurring->delivery_city,
            'cancel_requested_at' => $recurring->cancel_requested_at?->toIso8601String(),
            'cancel_request_note' => $recurring->cancel_request_note,
            'cancelled_at' => $recurring->cancelled_at?->toIso8601String(),
            'cancel_reason' => $recurring->cancel_reason,
            'completed_at' => $recurring->completed_at?->toIso8601String(),
            'created_at' => $recurring->created_at?->toIso8601String(),
            'clinic' => $recurring->relationLoaded('clinic') ? $recurring->clinic?->only(['id', 'name', 'city', 'email', 'phone']) : null,
        ];
    }
}
