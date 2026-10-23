<?php

namespace App\Services\Shop;

use App\Mail\NewOrderAdminMail;
use App\Mail\NewOrderClinicMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use App\Support\ShopAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Places an order for a clinic: prices, points, loyalty credit, stock and the
 * invoice for models billed straight away. Used by the checkout and by the
 * monthly run of multi-month orders.
 */
class ShopOrderPlacement
{
    public function __construct(
        private readonly WeeklyCycleService $weekly,
        private readonly ShopOrderSettlement $settlement,
    ) {
    }

    /**
     * @param  array<int, array{product: ShopProduct, qty: int, unit_sale?: float}>  $lines
     *         unit_sale defaults to the product's current price.
     * @param  array<string, mixed>  $delivery  delivery_* fields; the clinic's own details fill the gaps.
     * @param  array<string, mixed>  $attributes  extra ShopOrder attributes.
     *
     * @throws \RuntimeException when a weekly order has no open cycle.
     */
    public function place(
        ShopClinic $clinic,
        ?ShopOrderModel $orderModel,
        array $lines,
        array $delivery = [],
        array $attributes = [],
    ): ShopOrder {
        $subtotal = 0.0;
        $costSubtotal = 0.0;
        $itemRows = [];

        foreach ($lines as $line) {
            $product = $line['product'];
            $qty = (int) $line['qty'];
            $unitSale = (float) ($line['unit_sale'] ?? $product->price);
            $unitCost = $product->effectiveUnitCost($qty);

            $subtotal += $unitSale * $qty;
            $costSubtotal += $unitCost * $qty;

            $itemRows[] = [
                'product' => $product,
                'qty' => $qty,
                'unit_sale' => $unitSale,
                'unit_cost' => $unitCost,
                'row_sale' => $unitSale * $qty,
                'row_cost' => $unitCost * $qty,
            ];
        }

        // Services have no price/order model — they're a request for a quote,
        // not a billable order.
        $isServiceRequest = collect($itemRows)->every(fn ($row) => $row['product']->kind === 'service');

        $surchargeAmount = $orderModel ? (float) ($orderModel->surcharge_fixed_amount ?? 0) : 0.0;
        $total = $isServiceRequest ? 0.0 : $subtotal + $surchargeAmount;

        $isUrgent = ShopOrderSettlement::isUrgent($orderModel);

        // Weekly group orders join this week's cycle. They are paid only once
        // the group discount is approved, and earn the starter credit on payment.
        $isWeekly = !$isServiceRequest && WeeklyCycleService::isWeekly($orderModel);
        $cycle = $isWeekly ? $this->weekly->currentOpenCycle($orderModel) : null;

        $clinic->refresh();

        // Apply wallet credit — mandatory whenever available, not opt-in.
        $walletApplied = 0.0;
        if (!$isServiceRequest && $clinic->wallet_balance > 0) {
            $walletApplied = min((float) $clinic->wallet_balance, $total);
            $total = max(0.0, $total - $walletApplied);
        }

        // Every order earns 5% of what's actually paid (after this order's own
        // wallet deduction) as credit toward the next order. Urgent and weekly
        // orders are paid up front, so theirs is credited once they are paid.
        $loyaltyCredit = ($isUrgent || $isServiceRequest || $isWeekly) ? 0.0 : round($total * 0.05, 2);

        $status = $isServiceRequest ? 'new' : ($isUrgent ? 'new' : 'reserved');
        $paymentStatus = $isServiceRequest ? 'not_required' : ($isUrgent ? 'pending' : 'not_required');
        if ($isWeekly) {
            $paymentStatus = 'awaiting_discount';
        }

        $order = DB::transaction(function () use (
            $clinic, $orderModel, $cycle, $delivery, $attributes, $itemRows,
            $subtotal, $costSubtotal, $surchargeAmount, $total, $walletApplied, $loyaltyCredit,
            $status, $paymentStatus, $isServiceRequest
        ) {
            $order = ShopOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'shop_clinic_id' => $clinic->id,
                'shop_order_model_id' => $orderModel?->id,
                'shop_order_cycle_id' => $cycle?->id,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'subtotal' => round($subtotal, 2),
                'cost_subtotal' => round($costSubtotal, 2),
                'surcharge_amount' => $surchargeAmount,
                'total' => round($total, 2),
                'wallet_applied' => round($walletApplied, 2),
                'loyalty_credit_earned' => round($loyaltyCredit, 2),
                'currency' => 'MKD',
                'delivery_contact' => $delivery['delivery_contact'] ?? $clinic->contact_person,
                'delivery_phone' => $delivery['delivery_phone'] ?? $clinic->phone,
                'delivery_email' => $delivery['delivery_email'] ?? $clinic->email,
                'delivery_city' => $delivery['delivery_city'] ?? $clinic->city,
                'delivery_address' => $delivery['delivery_address'] ?? $clinic->address,
                'delivery_notes' => $delivery['delivery_notes'] ?? null,
                'placed_at' => now(),
                'payment_due_at' => $cycle?->payment_due_at,
                ...$attributes,
            ]);

            foreach ($itemRows as $row) {
                ShopOrderItem::create([
                    'shop_order_id' => $order->id,
                    'shop_product_id' => $row['product']->id,
                    'shop_vendor_id' => $row['product']->shop_vendor_id,
                    'product_name' => $row['product']->name,
                    'product_sku' => $row['product']->sku,
                    'kind' => $row['product']->kind,
                    'unit_sale_price' => $row['unit_sale'],
                    'unit_cost_price' => $row['unit_cost'],
                    'quantity' => $row['qty'],
                    'subtotal' => round($row['row_sale'], 2),
                    'cost_subtotal' => round($row['row_cost'], 2),
                ]);

                // Reduce stock for physical products
                if ($row['product']->kind === 'product' && $row['product']->stock !== null) {
                    $row['product']->decrement('stock', $row['qty']);
                }
            }

            // Debit wallet if applied
            if ($walletApplied > 0) {
                $newBalance = (float) $clinic->wallet_balance - $walletApplied;
                $clinic->update(['wallet_balance' => max(0, $newBalance)]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id' => $order->id,
                    'type' => 'debit',
                    'amount' => round($walletApplied, 2),
                    'balance_after' => round(max(0, $newBalance), 2),
                    'description' => "Применети поени при нарачка #{$order->order_number}",
                ]);
                $clinic->refresh();
            }

            // Credit the 5% loyalty reward for the next order
            if ($loyaltyCredit > 0) {
                $newBalance = (float) $clinic->wallet_balance + $loyaltyCredit;
                $clinic->update(['wallet_balance' => $newBalance]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id' => $order->id,
                    'type' => 'credit',
                    'amount' => round($loyaltyCredit, 2),
                    'balance_after' => round($newBalance, 2),
                    'description' => "5% кредит за наредна нарачка (нарачка #{$order->order_number})",
                ]);
                $clinic->refresh();
            }

            // Urgent and weekly orders are billed on their own invoice right away.
            if (!$isServiceRequest && ShopOrderSettlement::invoicedAtPlacement($orderModel)) {
                $this->settlement->openInvoice($order);
            }

            return $order;
        });

        return $order->refresh()->load(['orderModel', 'cycle', 'invoice', 'recurringOrder', 'items.product', 'items.product.vendor', 'clinic']);
    }

    // Fire the admin bell notification + email the admin and the clinic.
    // Never let a notification/mail failure break the order.
    public function notify(ShopOrder $order): void
    {
        $isServiceRequest = $order->items->isNotEmpty() && $order->items->every(fn ($i) => $i->kind === 'service');

        try {
            AdminNotification::notify(
                type: $isServiceRequest ? 'new_service_request' : 'new_order',
                title: $isServiceRequest ? 'Барање за понуда' : 'Нова нарачка',
                body: $isServiceRequest
                    ? "{$order->clinic?->name} побара понуда за услуга — нарачка {$order->order_number}. Контактирајте ја ординацијата."
                    : "{$order->clinic?->name} направи нарачка {$order->order_number} во износ од " . number_format((float) $order->total, 2) . ' ' . $order->currency,
                data: ['order_id' => $order->id, 'order_number' => $order->order_number],
                link: '/admin/shop-orders',
            );
        } catch (\Throwable $e) {
            Log::error('[order] admin notification failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        $adminEmail = ShopAdmin::emails();
        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
            } catch (\Throwable $e) {
                Log::error('[order] admin mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        if ($order->clinic?->email) {
            try {
                Mail::to($order->clinic->email)->send(new NewOrderClinicMail($order));
            } catch (\Throwable $e) {
                Log::error('[order] clinic mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function generateOrderNumber(): string
    {
        $year = now()->year;
        $prefix = "GNA-{$year}-";
        $last = ShopOrder::where('order_number', 'like', $prefix . '%')
            ->max(DB::raw("CAST(SUBSTR(order_number, " . (strlen($prefix) + 1) . ") AS INTEGER)"));
        $seq = ($last ?? 0) + 1;

        return sprintf('GNA-%d-%05d', $year, $seq);
    }
}
