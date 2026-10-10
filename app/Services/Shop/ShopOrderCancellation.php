<?php

namespace App\Services\Shop;

use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order and undoes what placing it did: stock comes back, wallet
 * credit spent on it is returned, and loyalty credit it earned is taken back.
 * Used by the clinic's own cancel and by the weekly payment deadline.
 */
class ShopOrderCancellation
{
    public function cancel(ShopOrder $order, ?string $adminNote = null): void
    {
        DB::transaction(function () use ($order, $adminNote) {
            $order->loadMissing(['items.product', 'clinic']);
            $clinic = $order->clinic;

            // Restore stock
            foreach ($order->items as $item) {
                if ($item->product && $item->product->kind === 'product' && $item->product->stock !== null) {
                    $item->product->increment('stock', $item->quantity);
                }
            }

            // Reverse wallet debit if any
            if ($clinic && $order->wallet_applied > 0) {
                $newBalance = (float) $clinic->wallet_balance + (float) $order->wallet_applied;
                $clinic->update(['wallet_balance' => $newBalance]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id'  => $order->id,
                    'type'           => 'credit',
                    'amount'         => (float) $order->wallet_applied,
                    'balance_after'  => round($newBalance, 2),
                    'description'    => "Враќање поени — откажана нарачка #{$order->order_number}",
                ]);
                $clinic->refresh();
            }

            // Reverse the loyalty credit this order earned, if any
            if ($clinic && $order->loyalty_credit_earned > 0) {
                $newBalance = (float) $clinic->wallet_balance - (float) $order->loyalty_credit_earned;
                $clinic->update(['wallet_balance' => max(0, $newBalance)]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id'  => $order->id,
                    'type'           => 'debit',
                    'amount'         => (float) $order->loyalty_credit_earned,
                    'balance_after'  => round(max(0, $newBalance), 2),
                    'description'    => "Поништен кредит — откажана нарачка #{$order->order_number}",
                ]);
            }

            $patch = [
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ];
            if ($adminNote) {
                $patch['admin_note'] = trim(($order->admin_note ? $order->admin_note . "\n" : '') . $adminNote);
            }
            $order->update($patch);
        });
    }
}
