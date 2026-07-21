<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopOrderRequestController extends Controller
{
    private const STATUSES = ['new', 'in_progress', 'resolved', 'rejected'];
    private const TYPES = ['wrong_quantity', 'missing_item', 'product_request', 'other'];

    // GET /api/shop-order-requests
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 5), 200);

        $query = ShopOrderRequest::query()
            ->with([
                'clinic:id,name,city,email,phone',
                'order:id,order_number,status',
            ])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            if ($status !== 'all' && in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($type = $request->query('type')) {
            if ($type !== 'all' && in_array($type, self::TYPES, true)) {
                $query->where('type', $type);
            }
        }
        if ($orderId = $request->query('order_id')) {
            $query->where('shop_order_id', (int) $orderId);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->whereHas('order', fn ($qq) => $qq->where('order_number', 'like', $like))
                    ->orWhereHas('clinic', fn ($qq) => $qq->where('name', 'like', $like)
                                                          ->orWhere('email', 'like', $like));
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // GET /api/shop-order-requests/stats
    public function stats(): JsonResponse
    {
        $byStatus = ShopOrderRequest::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'total' => (int) $byStatus->sum(),
            'by_status' => [
                'new'         => (int) ($byStatus['new'] ?? 0),
                'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
                'resolved'    => (int) ($byStatus['resolved'] ?? 0),
                'rejected'    => (int) ($byStatus['rejected'] ?? 0),
            ],
        ]);
    }

    // GET /api/shop-order-requests/{id}
    public function show(int $id): JsonResponse
    {
        $orderRequest = ShopOrderRequest::with(['clinic', 'order.items'])->findOrFail($id);

        return response()->json($orderRequest);
    }

    // PATCH /api/shop-order-requests/{id}/status
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status'     => 'required|string|in:' . implode(',', self::STATUSES),
            'admin_note' => 'nullable|string',
        ]);

        $orderRequest = ShopOrderRequest::findOrFail($id);

        $orderRequest->update([
            'status'      => $validated['status'],
            'admin_note'  => $validated['admin_note'] ?? $orderRequest->admin_note,
            'resolved_at' => in_array($validated['status'], ['resolved', 'rejected'], true)
                ? ($orderRequest->resolved_at ?? now())
                : null,
        ]);

        return response()->json($orderRequest->load(['clinic', 'order']));
    }
}
