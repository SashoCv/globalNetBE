<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopCategoryController extends Controller
{
    private const KINDS = ['product', 'service'];

    public function index(Request $request): JsonResponse
    {
        $query = ShopCategory::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($kind = $request->query('kind')) {
            if (in_array($kind, self::KINDS, true)) {
                $query->where('kind', $kind);
            }
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => 'required|in:' . implode(',', self::KINDS),
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:16',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) ShopCategory::max('sort_order') + 1;
        }
        return response()->json(ShopCategory::create($validated), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = ShopCategory::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'kind' => 'sometimes|in:' . implode(',', self::KINDS),
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:16',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);
        $c->update($validated);
        return response()->json($c->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        ShopCategory::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
