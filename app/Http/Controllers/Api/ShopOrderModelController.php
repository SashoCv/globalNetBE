<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopOrderModelController extends Controller
{
    // Admin: GET /api/shop-order-models — all (active + inactive)
    public function index(): JsonResponse
    {
        return response()->json(
            ShopOrderModel::orderBy('sort_order')->orderBy('id')->get()
        );
    }

    // Admin: POST /api/shop-order-models
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) ShopOrderModel::max('sort_order') + 1;
        }
        $model = ShopOrderModel::create($validated);
        return response()->json($model, 201);
    }

    // Admin: PUT /api/shop-order-models/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $model = ShopOrderModel::findOrFail($id);
        $validated = $this->validatePayload($request, $model);
        $model->update($validated);
        return response()->json($model->fresh());
    }

    // Admin: DELETE /api/shop-order-models/{id}
    public function destroy(int $id): JsonResponse
    {
        ShopOrderModel::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // Public: GET /api/public/shop-order-models — only active
    public function publicIndex(): JsonResponse
    {
        return response()->json(
            ShopOrderModel::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    private function validatePayload(Request $request, ?ShopOrderModel $existing = null): array
    {
        $rule = $existing ? 'sometimes' : 'required';
        return $request->validate([
            'code' => "$rule|string|max:64|unique:shop_order_models,code" . ($existing ? ',' . $existing->id : ''),
            'label' => 'nullable|string|max:64',
            'title' => "$rule|string|max:255",
            'tagline' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'bullets' => 'nullable|array',
            'bullets.*' => 'string|max:255',
            'icon' => 'nullable|string|max:64',
            'accent_color' => 'nullable|string|max:16',
            'accent_color_soft' => 'nullable|string|max:16',
            'cutoff_day' => 'nullable|integer|min:1|max:31',
            'cutoff_hour' => 'nullable|integer|min:0|max:23',
            'delivery_day' => 'nullable|integer|min:1|max:31',
            'max_delivery_hours' => 'nullable|integer|min:1|max:999',
            'repeat_months' => 'nullable|integer|min:0|max:24',
            'auto_repeat' => 'nullable|boolean',
            'min_order_amount' => 'nullable|numeric|min:0',
            'surcharge_fixed_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
    }
}
