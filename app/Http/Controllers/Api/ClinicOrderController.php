<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopOrder;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use App\Services\Shop\RecurringOrderService;
use App\Services\Shop\ShopOrderCancellation;
use App\Services\Shop\ShopOrderPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicOrderController extends Controller
{
    public function __construct(
        private readonly ShopOrderCancellation $cancellation,
        private readonly ShopOrderPlacement $placement,
        private readonly RecurringOrderService $recurring,
    ) {
    }

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
            'months'             => 'nullable|integer',
            'delivery_contact'   => 'nullable|string|max:255',
            'delivery_phone'     => 'nullable|string|max:50',
            'delivery_email'     => 'nullable|email|max:255',
            'delivery_city'      => 'nullable|string|max:255',
            'delivery_address'   => 'nullable|string|max:500',
            'delivery_notes'     => 'nullable|string',
        ]);

        $lines = [];
        foreach ($validated['items'] as $cartItem) {
            $product = ShopProduct::with('vendor')->findOrFail($cartItem['product_id']);

            if ($product->status !== 'active') {
                return response()->json([
                    'message' => 'Производот "' . $product->name . '" не е достапен.',
                ], 422);
            }

            $lines[] = ['product' => $product, 'qty' => (int) $cartItem['quantity']];
        }

        // Services have no price/order model — they're a request for a quote,
        // not a billable order. Everything else still needs a delivery model.
        $isServiceRequest = collect($lines)->every(fn ($line) => $line['product']->kind === 'service');

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

        // A multi-month order says for how many months it repeats.
        $months = null;
        if (!$isServiceRequest && RecurringOrderService::isRecurring($orderModel)) {
            $months = (int) ($validated['months'] ?? 0);
            if (!in_array($months, RecurringOrderService::monthOptions(), true)) {
                return response()->json([
                    'message' => 'Изберете за колку месеци е повеќемесечната нарачка.',
                    'errors' => ['months' => ['Изберете број на месеци.']],
                ], 422);
            }
        }

        try {
            $order = DB::transaction(function () use ($clinic, $orderModel, $lines, $validated, $months) {
                $order = $this->placement->place($clinic, $orderModel, $lines, $validated);

                if ($months) {
                    $this->recurring->start($order, $months);
                    $order->load('recurringOrder');
                }

                return $order;
            });
        } catch (\PDOException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // No weekly cycle open to join.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->placement->notify($order);

        return response()->json($order, 201);
    }

    // GET /api/clinic/orders
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $orders = ShopOrder::with(['orderModel', 'cycle', 'recurringOrder', 'items'])
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

        $order = ShopOrder::with(['orderModel', 'cycle', 'recurringOrder', 'items.product', 'items.product.vendor'])
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

        $this->cancellation->cancel($order);

        return response()->json(['message' => 'Нарачката е откажана.']);
    }
}
