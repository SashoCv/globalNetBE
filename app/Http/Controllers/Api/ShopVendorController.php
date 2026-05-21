<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopVendorController extends Controller
{
    private const STATUSES = ['active', 'pending', 'cancel'];

    // GET /api/shop-vendors/{id}
    public function show(int $id): JsonResponse
    {
        $vendor = ShopVendor::with(['products' => function ($q) {
            $q->with('category:id,name,slug,kind,color')
              ->orderBy('sort_order')->orderBy('id');
        }])->findOrFail($id);

        return response()->json($vendor);
    }

    // GET /api/shop-vendors
    public function index(Request $request): JsonResponse
    {
        $query = ShopVendor::query()->orderBy('sort_order')->orderBy('id');

        if ($status = $request->query('status')) {
            if ($status !== 'all' && in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        return response()->json($query->get());
    }

    // POST /api/shop-vendors
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) ShopVendor::max('sort_order') + 1;
        }

        $vendor = ShopVendor::create($validated);
        return response()->json($vendor, 201);
    }

    // PUT /api/shop-vendors/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $vendor = ShopVendor::findOrFail($id);
        $validated = $this->validatePayload($request, $vendor);
        $vendor->update($validated);
        return response()->json($vendor->fresh());
    }

    // DELETE /api/shop-vendors/{id}
    public function destroy(int $id): JsonResponse
    {
        ShopVendor::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // POST /api/shop-vendors/reorder  body: { ids: [3, 1, 2, ...] }
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:shop_vendors,id',
        ]);

        foreach ($validated['ids'] as $i => $id) {
            ShopVendor::where('id', $id)->update(['sort_order' => $i + 1]);
        }
        return response()->json(['message' => 'ok']);
    }

    private function validatePayload(Request $request, ?ShopVendor $existing = null): array
    {
        $rule = $existing ? 'sometimes' : 'required';
        return $request->validate([
            'name' => "$rule|string|max:255",
            'edb' => 'nullable|string|max:32',
            'slug' => 'nullable|string|max:255|unique:shop_vendors,slug' . ($existing ? ',' . $existing->id : ''),
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:500',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'sort_order' => 'nullable|integer',
        ]);
    }
}
