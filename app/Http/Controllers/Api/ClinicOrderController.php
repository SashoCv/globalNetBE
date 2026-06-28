<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopClinicWalletTransaction;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicOrderController extends Controller
{
    // POST /api/clinic/orders
    public function store(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $validated = $request->validate([
            'order_model_code'   => 'required|string|exists:shop_order_models,code',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:shop_products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'apply_wallet'       => 'boolean',
            'delivery_contact'   => 'nullable|string|max:255',
            'delivery_phone'     => 'nullable|string|max:50',
            'delivery_email'     => 'nullable|email|max:255',
            'delivery_city'      => 'nullable|string|max:255',
            'delivery_address'   => 'nullable|string|max:500',
            'delivery_notes'     => 'nullable|string',
        ]);

        $orderModel = ShopOrderModel::where('code', $validated['order_model_code'])
            ->where('is_active', true)
            ->firstOrFail();

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

        $surchargePercent = (float) ($orderModel->surcharge_percent ?? 0);
        $surchargeAmount  = round($subtotal * $surchargePercent / 100, 2);
        $total            = $subtotal + $surchargeAmount;

        // Apply wallet credit
        $walletApplied = 0.0;
        if (!empty($validated['apply_wallet']) && $clinic->wallet_balance > 0) {
            $walletApplied = min((float) $clinic->wallet_balance, $total);
            $total         = max(0.0, $total - $walletApplied);
        }

        // Determine status & payment_status
        $isUrgent = $orderModel->code === 'urgent';
        $status        = $isUrgent ? 'new' : 'reserved';
        $paymentStatus = $isUrgent ? 'pending' : 'not_required';

        $order = DB::transaction(function () use (
            $clinic, $orderModel, $validated, $itemRows,
            $subtotal, $costSubtotal, $surchargeAmount, $total, $walletApplied,
            $status, $paymentStatus
        ) {
            $order = ShopOrder::create([
                'order_number'       => $this->generateOrderNumber(),
                'shop_clinic_id'     => $clinic->id,
                'shop_order_model_id'=> $orderModel->id,
                'status'             => $status,
                'payment_status'     => $paymentStatus,
                'subtotal'           => round($subtotal, 2),
                'cost_subtotal'      => round($costSubtotal, 2),
                'surcharge_amount'   => $surchargeAmount,
                'total'              => round($total, 2),
                'wallet_applied'     => round($walletApplied, 2),
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

            return $order;
        });

        return response()->json(
            $order->load(['orderModel', 'items.product', 'items.product.vendor', 'clinic']),
            201
        );
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
