<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderAdminMail;
use App\Mail\NewOrderClinicMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ClinicOrderController extends Controller
{
    // POST /api/clinic/orders
    public function store(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $validated = $request->validate([
            'order_model_code'   => 'nullable|string|exists:shop_order_models,code',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:shop_products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'delivery_contact'   => 'nullable|string|max:255',
            'delivery_phone'     => 'nullable|string|max:50',
            'delivery_email'     => 'nullable|email|max:255',
            'delivery_city'      => 'nullable|string|max:255',
            'delivery_address'   => 'nullable|string|max:500',
            'delivery_notes'     => 'nullable|string',
        ]);

        // Build item rows and calculate totals
        $subtotal     = 0.0;
        $costSubtotal = 0.0;
        $itemRows     = [];

        foreach ($validated['items'] as $cartItem) {
            $product = ShopProduct::with('vendor')->findOrFail($cartItem['product_id']);

            if ($product->status !== 'active') {
                return response()->json([
                    'message' => 'Производот "' . $product->name . '" не е достапен.',
                ], 422);
            }

            $qty        = (int) $cartItem['quantity'];
            $unitSale   = (float) $product->price;
            $unitCost   = $product->effectiveUnitCost($qty);
            $rowSale    = $unitSale * $qty;
            $rowCost    = $unitCost * $qty;

            $subtotal     += $rowSale;
            $costSubtotal += $rowCost;

            $itemRows[] = [
                'product'        => $product,
                'qty'            => $qty,
                'unit_sale'      => $unitSale,
                'unit_cost'      => $unitCost,
                'row_sale'       => $rowSale,
                'row_cost'       => $rowCost,
            ];
        }

        // Services have no price/order model — they're a request for a quote,
        // not a billable order. Everything else still needs a delivery model.
        $isServiceRequest = collect($itemRows)->every(fn ($row) => $row['product']->kind === 'service');

        $orderModel = null;
        if (!empty($validated['order_model_code'])) {
            $orderModel = ShopOrderModel::where('code', $validated['order_model_code'])
                ->where('is_active', true)
                ->firstOrFail();
        } elseif (!$isServiceRequest) {
            return response()->json([
                'message' => 'Изберете модел на нарачка.',
            ], 422);
        }

        $surchargeAmount = $orderModel ? (float) ($orderModel->surcharge_fixed_amount ?? 0) : 0.0;
        $total           = $isServiceRequest ? 0.0 : $subtotal + $surchargeAmount;

        $isUrgent = $orderModel?->code === 'urgent';

        // Apply wallet credit — mandatory whenever available, not opt-in.
        $walletApplied = 0.0;
        if (!$isServiceRequest && $clinic->wallet_balance > 0) {
            $walletApplied = min((float) $clinic->wallet_balance, $total);
            $total         = max(0.0, $total - $walletApplied);
        }

        // Every non-urgent order earns 5% of what's actually paid (after this
        // order's own wallet deduction) as credit toward the next order.
        $loyaltyCredit = ($isUrgent || $isServiceRequest) ? 0.0 : round($total * 0.05, 2);

        // Determine status & payment_status
        $status        = $isServiceRequest ? 'new' : ($isUrgent ? 'new' : 'reserved');
        $paymentStatus = $isServiceRequest ? 'not_required' : ($isUrgent ? 'pending' : 'not_required');

        $order = DB::transaction(function () use (
            $clinic, $orderModel, $validated, $itemRows,
            $subtotal, $costSubtotal, $surchargeAmount, $total, $walletApplied, $loyaltyCredit,
            $status, $paymentStatus
        ) {
            $order = ShopOrder::create([
                'order_number'       => $this->generateOrderNumber(),
                'shop_clinic_id'     => $clinic->id,
                'shop_order_model_id'=> $orderModel?->id,
                'status'             => $status,
                'payment_status'     => $paymentStatus,
                'subtotal'           => round($subtotal, 2),
                'cost_subtotal'      => round($costSubtotal, 2),
                'surcharge_amount'   => $surchargeAmount,
                'total'              => round($total, 2),
                'wallet_applied'     => round($walletApplied, 2),
                'loyalty_credit_earned' => round($loyaltyCredit, 2),
                'currency'           => 'MKD',
                'delivery_contact'   => $validated['delivery_contact'] ?? $clinic->contact_person,
                'delivery_phone'     => $validated['delivery_phone'] ?? $clinic->phone,
                'delivery_email'     => $validated['delivery_email'] ?? $clinic->email,
                'delivery_city'      => $validated['delivery_city'] ?? $clinic->city,
                'delivery_address'   => $validated['delivery_address'] ?? $clinic->address,
                'delivery_notes'     => $validated['delivery_notes'] ?? null,
                'placed_at'          => now(),
            ]);

            foreach ($itemRows as $row) {
                ShopOrderItem::create([
                    'shop_order_id'   => $order->id,
                    'shop_product_id' => $row['product']->id,
                    'shop_vendor_id'  => $row['product']->shop_vendor_id,
                    'product_name'    => $row['product']->name,
                    'product_sku'     => $row['product']->sku,
                    'kind'            => $row['product']->kind,
                    'unit_sale_price' => $row['unit_sale'],
                    'unit_cost_price' => $row['unit_cost'],
                    'quantity'        => $row['qty'],
                    'subtotal'        => round($row['row_sale'], 2),
                    'cost_subtotal'   => round($row['row_cost'], 2),
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
                    'shop_order_id'  => $order->id,
                    'type'           => 'debit',
                    'amount'         => round($walletApplied, 2),
                    'balance_after'  => round(max(0, $newBalance), 2),
                    'description'    => "Применети поени при нарачка #{$order->order_number}",
                ]);
                $clinic->refresh();
            }

            // Credit the 5% loyalty reward for the next order
            if ($loyaltyCredit > 0) {
                $newBalance = (float) $clinic->wallet_balance + $loyaltyCredit;
                $clinic->update(['wallet_balance' => $newBalance]);
                ShopClinicWalletTransaction::create([
                    'shop_clinic_id' => $clinic->id,
                    'shop_order_id'  => $order->id,
                    'type'           => 'credit',
                    'amount'         => round($loyaltyCredit, 2),
                    'balance_after'  => round($newBalance, 2),
                    'description'    => "5% кредит за наредна нарачка (нарачка #{$order->order_number})",
                ]);
                $clinic->refresh();
            }

            return $order;
        });

        $order->load(['orderModel', 'items.product', 'items.product.vendor', 'clinic']);

        $this->notifyNewOrder($order);

        return response()->json($order, 201);
    }

    // Fire the admin bell notification + email the admin and the clinic.
    // Never let a notification/mail failure break the order response.
    private function notifyNewOrder(ShopOrder $order): void
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

        $adminEmail = config('app.shop_admin_email');
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

    // GET /api/clinic/orders
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $orders = ShopOrder::with(['orderModel', 'items'])
            ->where('shop_clinic_id', $clinic->id)
            ->orderByDesc('placed_at')
            ->paginate(20);

        return response()->json($orders);
    }

    // GET /api/clinic/orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $order = ShopOrder::with(['orderModel', 'items.product', 'items.product.vendor'])
            ->where('shop_clinic_id', $clinic->id)
            ->findOrFail($id);

        return response()->json($order);
    }

    // PATCH /api/clinic/orders/{id}/cancel
    public function cancel(Request $request, int $id): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $order = ShopOrder::where('shop_clinic_id', $clinic->id)->findOrFail($id);

        $cancellable = $order->status === 'reserved'
            || ($order->status === 'new' && $order->payment_status === 'pending');

        if (!$cancellable) {
            return response()->json(['message' => 'Оваа нарачка не може да се откажи.'], 422);
        }

        DB::transaction(function () use ($order, $clinic) {
            // Restore stock
            foreach ($order->items as $item) {
                if ($item->product && $item->product->kind === 'product' && $item->product->stock !== null) {
                    $item->product->increment('stock', $item->quantity);
                }
            }

            // Reverse wallet debit if any
            if ($order->wallet_applied > 0) {
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
            if ($order->loyalty_credit_earned > 0) {
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

            $order->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Нарачката е откажана.']);
    }

    private function generateOrderNumber(): string
    {
        $year = now()->year;
        $prefix = "GNA-{$year}-";
        $last = ShopOrder::where('order_number', 'like', $prefix . '%')
            ->max(DB::raw("CAST(SUBSTR(order_number, " . (strlen($prefix) + 1) . ") AS INTEGER)"));
        $seq  = ($last ?? 0) + 1;
        return sprintf('GNA-%d-%05d', $year, $seq);
    }
}
