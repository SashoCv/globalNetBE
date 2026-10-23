<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopRecurringOrder;
use App\Services\Shop\RecurringOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of multi-month orders. Only the admin cancels one, usually on
 * the clinic's request.
 */
class ShopRecurringOrderController extends Controller
{
    public function __construct(
        private readonly RecurringOrderService $recurring,
    ) {
    }

    // GET /api/shop-recurring-orders?status=active|completed|cancelled|cancel_requested
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $list = ShopRecurringOrder::with(['clinic', 'items.product', 'orders' => fn ($q) => $q->orderBy('recurring_month')])
            ->when($status === 'cancel_requested', fn ($q) => $q->where('status', 'active')->whereNotNull('cancel_requested_at'))
            ->when($status && $status !== 'cancel_requested', fn ($q) => $q->where('status', $status))
            ->orderByRaw('CASE WHEN cancel_requested_at IS NOT NULL AND status = ? THEN 0 ELSE 1 END', [ShopRecurringOrder::STATUS_ACTIVE])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShopRecurringOrder $r) => RecurringOrderPresenter::present($r, $this->recurring));

        return response()->json($list);
    }

    // GET /api/shop-recurring-orders/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->present(ShopRecurringOrder::findOrFail($id)));
    }

    // PATCH /api/shop-recurring-orders/{id}/cancel
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:2000']);

        $recurring = $this->recurring->cancel(ShopRecurringOrder::findOrFail($id), $validated['reason'] ?? null);

        return response()->json($this->present($recurring));
    }

    // PATCH /api/shop-recurring-orders/{id}/decline-cancel
    public function declineCancellation(int $id): JsonResponse
    {
        $recurring = $this->recurring->declineCancellation(ShopRecurringOrder::findOrFail($id));

        return response()->json($this->present($recurring));
    }

    private function present(ShopRecurringOrder $recurring): array
    {
        $recurring->load(['clinic', 'items.product', 'orders' => fn ($q) => $q->orderBy('recurring_month')]);

        return RecurringOrderPresenter::present($recurring, $this->recurring);
    }
}
