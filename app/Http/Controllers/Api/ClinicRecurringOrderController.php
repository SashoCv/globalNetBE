<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopClinic;
use App\Models\ShopRecurringOrder;
use App\Services\Shop\RecurringOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A clinic's multi-month orders: see them, change what is ordered from the
 * next month on, and ask for a cancellation.
 */
class ClinicRecurringOrderController extends Controller
{
    public function __construct(
        private readonly RecurringOrderService $recurring,
    ) {
    }

    // GET /api/clinic/recurring-orders
    public function index(Request $request): JsonResponse
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        $list = ShopRecurringOrder::where('shop_clinic_id', $clinic->id)
            ->with(['items.product', 'orders' => fn ($q) => $q->orderBy('recurring_month')])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShopRecurringOrder $r) => $this->present($r));

        return response()->json($list);
    }

    // GET /api/clinic/recurring-orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->present($this->find($request, $id)));
    }

    // PUT /api/clinic/recurring-orders/{id}/items
    public function updateItems(Request $request, int $id): JsonResponse
    {
        $recurring = $this->find($request, $id);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:shop_products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
        ]);

        $recurring = $this->recurring->updateItems($recurring, $validated['items']);

        return response()->json($this->present($recurring));
    }

    // POST /api/clinic/recurring-orders/{id}/cancel-request
    public function requestCancellation(Request $request, int $id): JsonResponse
    {
        $recurring = $this->find($request, $id);

        $validated = $request->validate(['note' => 'nullable|string|max:2000']);

        $recurring = $this->recurring->requestCancellation($recurring, $validated['note'] ?? null);

        return response()->json($this->present($recurring));
    }

    private function find(Request $request, int $id): ShopRecurringOrder
    {
        /** @var ShopClinic $clinic */
        $clinic = $request->user();

        return ShopRecurringOrder::where('shop_clinic_id', $clinic->id)->findOrFail($id);
    }

    private function present(ShopRecurringOrder $recurring): array
    {
        $recurring->loadMissing(['items.product', 'orders' => fn ($q) => $q->orderBy('recurring_month')]);

        return RecurringOrderPresenter::present($recurring, $this->recurring);
    }
}
