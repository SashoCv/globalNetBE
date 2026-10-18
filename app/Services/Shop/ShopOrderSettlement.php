<?php

namespace App\Services\Shop;

use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Urgent and weekly orders are paid up front, so each one gets its own invoice
 * the moment it is placed — monthly orders keep waiting for the pro-invoice of
 * the 20th. The order and its invoice are two handles on the same debt: paying
 * either one settles both, and cancelling the order cancels its invoice.
 */
class ShopOrderSettlement
{
    public const METHOD_WALLET = 'Поени';

    public static function invoicedAtPlacement(?ShopOrderModel $model): bool
    {
        return $model !== null && in_array($model->code, [
            config('shop.urgent.model_code'),
            config('shop.weekly.model_code'),
        ], true);
    }

    public static function isUrgent(?ShopOrderModel $model): bool
    {
        return $model !== null && $model->code === config('shop.urgent.model_code');
    }

    /**
     * Issue the order's own invoice. A weekly invoice is due with the cycle's
     * payment deadline, an urgent one right away.
     */
    public function openInvoice(ShopOrder $order): ShopInvoice
    {
        $tz = config('shop.weekly.timezone');
        $placed = Carbon::parse($order->placed_at ?? now())->setTimezone($tz)->toDateString();
        $due = Carbon::parse($order->payment_due_at ?? now())->setTimezone($tz)->toDateString();

        $invoice = DB::transaction(function () use ($order, $placed, $due) {
            $invoice = ShopInvoice::create([
                'invoice_number'   => ShopInvoice::nextNumber(),
                'shop_clinic_id'   => $order->shop_clinic_id,
                'period_from'      => $placed,
                'period_to'        => $placed,
                ...ShopInvoice::amountsOf([$order]),
                'currency'         => $order->currency,
                'status'           => 'pending',
                'issued_at'        => now(),
                'due_at'           => $due,
            ]);

            $order->update(['shop_invoice_id' => $invoice->id]);

            return $invoice;
        });

        // Wallet credit covered the whole urgent order — nothing left to pay.
        // (A weekly order waits for the group discount whatever its total.)
        if ($order->payment_status === 'pending' && (float) $order->total <= 0) {
            $this->orderPaid($order, self::METHOD_WALLET);
        }

        return $invoice->fresh();
    }

    /**
     * An order's amounts changed (group discount, admin edit): its unpaid
     * invoice follows.
     */
    public function syncInvoice(ShopOrder $order): void
    {
        $invoice = $order->shop_invoice_id ? ShopInvoice::find($order->shop_invoice_id) : null;

        if (! $invoice || $invoice->status !== 'pending') {
            return;
        }

        $invoice->update(ShopInvoice::amountsOf(
            $invoice->orders()->where('status', '!=', 'cancelled')->get()
        ));
    }

    /**
     * An order was paid — card, bank transfer marked by the admin, or nothing
     * left to pay. Safe to call more than once.
     */
    public function orderPaid(ShopOrder $order, ?string $method): void
    {
        if ($order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'paid']);
        }

        $order->loadMissing('orderModel', 'clinic');

        if ($order->shop_order_cycle_id) {
            app(WeeklyCycleService::class)->orderPaid($order);
            $this->creditLoyalty($order, (float) config('shop.weekly.loyalty_percent'));
        } elseif (self::isUrgent($order->orderModel)) {
            $this->creditLoyalty($order, (float) config('shop.urgent.loyalty_percent'));
        }

        $invoice = $order->shop_invoice_id ? ShopInvoice::find($order->shop_invoice_id) : null;

        // Only invoices made of prepaid orders settle this way; a monthly
        // invoice is paid as a whole.
        if ($invoice && $invoice->status === 'pending'
            && ! $invoice->orders()->where('status', '!=', 'cancelled')->where('payment_status', '!=', 'paid')->exists()) {
            $invoice->update([
                'status'         => 'paid',
                'paid_at'        => now(),
                'payment_method' => $method ?? $invoice->payment_method,
            ]);
        }
    }

    /**
     * An invoice was paid — its prepaid orders are paid with it.
     */
    public function invoicePaid(ShopInvoice $invoice, ?string $method): void
    {
        $invoice->update([
            'status'         => 'paid',
            'paid_at'        => $invoice->paid_at ?? now(),
            'payment_method' => $method ?? $invoice->payment_method,
        ]);

        $orders = $invoice->orders()
            ->where('status', '!=', 'cancelled')
            ->where('payment_status', 'pending')
            ->get();

        foreach ($orders as $order) {
            $this->orderPaid($order, $method);
        }
    }

    /**
     * Why an order cannot be paid right now, or null when it can.
     */
    public function notPayableReason(ShopOrder $order): ?string
    {
        if ($order->status === 'cancelled') {
            return 'Нарачката е откажана.';
        }

        // Weekly group orders are paid between the discount approval and the
        // Tuesday deadline, and only for the amount after the group discount.
        if ($order->shop_order_cycle_id) {
            if ($order->payment_status === 'awaiting_discount') {
                return 'Збирниот попуст сè уште не е одобрен. Ќе добиете е-пошта со конечниот износ до вторник во 08:00.';
            }

            if ($order->payment_due_at && now()->greaterThanOrEqualTo($order->payment_due_at)) {
                $due = app(WeeklyCycleService::class)->formatLocal($order->payment_due_at);

                return "Рокот за плаќање истече ({$due}).";
            }
        }

        return null;
    }

    /**
     * The order is gone: an invoice left with nothing on it is cancelled,
     * otherwise it shrinks to the orders that remain.
     */
    public function orderCancelled(ShopOrder $order): void
    {
        $invoice = $order->shop_invoice_id ? ShopInvoice::find($order->shop_invoice_id) : null;

        if (! $invoice || $invoice->status !== 'pending') {
            return;
        }

        if ($invoice->orders()->where('status', '!=', 'cancelled')->exists()) {
            $this->syncInvoice($order);

            return;
        }

        $invoice->update(['status' => 'cancelled']);
    }

    /**
     * Starter credit for the next order, a share of what was actually paid.
     * Earned once; a cancellation takes it back.
     */
    private function creditLoyalty(ShopOrder $order, float $percent): void
    {
        $clinic = $order->clinic;
        $credit = round((float) $order->total * $percent / 100, 2);

        if ($credit <= 0 || ! $clinic || $order->status === 'cancelled' || (float) $order->loyalty_credit_earned > 0) {
            return;
        }

        DB::transaction(function () use ($order, $clinic, $credit, $percent) {
            $newBalance = (float) $clinic->wallet_balance + $credit;
            $clinic->update(['wallet_balance' => $newBalance]);
            ShopClinicWalletTransaction::create([
                'shop_clinic_id' => $clinic->id,
                'shop_order_id'  => $order->id,
                'type'           => 'credit',
                'amount'         => $credit,
                'balance_after'  => round($newBalance, 2),
                'description'    => rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . "% кредит за наредна нарачка (нарачка #{$order->order_number})",
            ]);
            $order->update(['loyalty_credit_earned' => $credit]);
        });
    }
}
