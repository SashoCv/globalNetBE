<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopOrderController extends Controller
{
    private const STATUSES = ['new', 'confirmed', 'processing', 'in_delivery', 'completed', 'cancelled'];

    // GET /api/shop-orders (paginated, filtered)
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 5), 200);

        $query = ShopOrder::query()
            ->with([
                'clinic:id,name,city,email,phone',
                'orderModel:id,code,label,title,accent_color',
                'items:id,shop_order_id,product_name,quantity,unit_sale_price,subtotal',
            ])
            ->withCount('items')
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            if ($status !== 'all' && in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($modelCode = $request->query('model')) {
            $query->whereHas('orderModel', fn ($q) => $q->where('code', $modelCode));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('order_number', 'like', $like)
                    ->orWhereHas('clinic', fn ($qq) => $qq->where('name', 'like', $like)
                                                          ->orWhere('email', 'like', $like));
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // GET /api/shop-orders/stats
    public function stats(): JsonResponse
    {
        $byStatus = ShopOrder::query()
            ->selectRaw('status, COUNT(*) as cnt, SUM(total) as revenue')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $byModel = ShopOrder::query()
            ->selectRaw('shop_order_model_id, COUNT(*) as cnt')
            ->groupBy('shop_order_model_id')
            ->pluck('cnt', 'shop_order_model_id');

        $totalRevenue = (float) ShopOrder::where('status', 'completed')->sum('total');
        $totalProfit = (float) ShopOrder::where('status', 'completed')->sum('subtotal')
                     - (float) ShopOrder::where('status', 'completed')->sum('cost_subtotal');

        return response()->json([
            'total' => $byStatus->sum('cnt'),
            'by_status' => [
                'new' => (int) ($byStatus['new']->cnt ?? 0),
                'confirmed' => (int) ($byStatus['confirmed']->cnt ?? 0),
                'processing' => (int) ($byStatus['processing']->cnt ?? 0),
                'in_delivery' => (int) ($byStatus['in_delivery']->cnt ?? 0),
                'completed' => (int) ($byStatus['completed']->cnt ?? 0),
                'cancelled' => (int) ($byStatus['cancelled']->cnt ?? 0),
            ],
            'by_model' => $byModel,
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalProfit,
        ]);
    }

    // GET /api/shop-orders/{id}
    public function show(int $id): JsonResponse
    {
        $order = ShopOrder::query()
            ->with([
                'clinic:id,name,email,phone,city,address,edb',
                'orderModel',
                'items',
                'items.vendor:id,name,slug',
                'items.product:id,slug,image',
            ])
            ->findOrFail($id);

        return response()->json($order);
    }

    // PATCH /api/shop-orders/{id}/status
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', self::STATUSES),
            'admin_note' => 'nullable|string',
        ]);

        $order = ShopOrder::findOrFail($id);
        $patch = ['status' => $validated['status']];
        if (!empty($validated['admin_note'])) $patch['admin_note'] = $validated['admin_note'];
        if ($validated['status'] === 'cancelled') $patch['cancelled_at'] = now();
        if ($validated['status'] === 'completed') $patch['completed_at'] = now();
        $order->update($patch);

        return response()->json($order->fresh());
    }

    // DELETE /api/shop-orders/{id}
    public function destroy(int $id): JsonResponse
    {
        ShopOrder::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
