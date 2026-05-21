<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopProductController extends Controller
{
    private const KINDS = ['product', 'service'];
    private const STATUSES = ['draft', 'active', 'archived'];

    // GET /api/shop-products/{id}
    public function show(int $id): JsonResponse
    {
        $product = ShopProduct::with([
            'vendor:id,name,slug,logo,status,phone,email,city',
            'category:id,name,slug,kind,color',
        ])->findOrFail($id);

        return response()->json($product);
    }

    // GET /api/shop-products  (global, with filters)
    public function index(Request $request): JsonResponse
    {
        $query = ShopProduct::query()
            ->with([
                'vendor:id,name,slug,logo,status',
                'category:id,name,slug,kind,color',
            ])
            ->orderBy('shop_vendor_id')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($vendorId = $request->query('vendor_id')) {
            $query->where('shop_vendor_id', (int) $vendorId);
        }
        if ($kind = $request->query('kind')) {
            if (in_array($kind, self::KINDS, true)) {
                $query->where('kind', $kind);
            }
        }
        if ($categoryId = $request->query('category_id')) {
            if ($categoryId === 'none') $query->whereNull('shop_category_id');
            else $query->where('shop_category_id', (int) $categoryId);
        }
        if ($status = $request->query('status')) {
            if (in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('short_description', 'like', $like);
            });
        }

        return response()->json($query->get());
    }

    // GET /api/shop-vendors/{vendor}/products
    public function indexForVendor(Request $request, int $vendorId): JsonResponse
    {
        ShopVendor::findOrFail($vendorId);

        $query = ShopProduct::where('shop_vendor_id', $vendorId)
            ->with('category:id,name,slug,kind,color')
            ->orderBy('sort_order')->orderBy('id');

        if ($kind = $request->query('kind')) {
            if (in_array($kind, self::KINDS, true)) {
                $query->where('kind', $kind);
            }
        }
        if ($categoryId = $request->query('category_id')) {
            if ($categoryId === 'none') {
                $query->whereNull('shop_category_id');
            } else {
                $query->where('shop_category_id', (int) $categoryId);
            }
        }
        if ($status = $request->query('status')) {
            if (in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('short_description', 'like', $like);
            });
        }

        return response()->json($query->get());
    }

    // POST /api/shop-vendors/{vendor}/products
    public function store(Request $request, int $vendorId): JsonResponse
    {
        ShopVendor::findOrFail($vendorId);

        $validated = $this->validatePayload($request);
        $validated['shop_vendor_id'] = $vendorId;
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) ShopProduct::where('shop_vendor_id', $vendorId)->max('sort_order') + 1;
        }
        $product = ShopProduct::create($validated);
        $this->refreshVendorCount($vendorId);
        return response()->json($product->load('category:id,name,slug,kind,color'), 201);
    }

    // PUT /api/shop-products/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $product = ShopProduct::findOrFail($id);
        $oldVendorId = $product->shop_vendor_id;
        $validated = $this->validatePayload($request, $product);
        $product->update($validated);

        // Refresh counts on both vendors if reassigned
        $this->refreshVendorCount($product->shop_vendor_id);
        if ($oldVendorId !== $product->shop_vendor_id) {
            $this->refreshVendorCount($oldVendorId);
        }

        return response()->json($product->fresh()->load([
            'vendor:id,name,slug,logo,status',
            'category:id,name,slug,kind,color',
        ]));
    }

    // DELETE /api/shop-products/{id}
    public function destroy(int $id): JsonResponse
    {
        $product = ShopProduct::findOrFail($id);
        $vendorId = $product->shop_vendor_id;
        $product->delete();
        $this->refreshVendorCount($vendorId);
        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, ?ShopProduct $existing = null): array
    {
        $rule = $existing ? 'sometimes' : 'required';
        return $request->validate([
            'shop_vendor_id' => 'sometimes|integer|exists:shop_vendors,id',
            'shop_category_id' => 'nullable|integer|exists:shop_categories,id',
            'name' => "$rule|string|max:255",
            'sku' => 'nullable|string|max:64',
            'kind' => "nullable|in:" . implode(',', self::KINDS),
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'stock' => 'nullable|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:500',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
    }

    private function refreshVendorCount(int $vendorId): void
    {
        $count = ShopProduct::where('shop_vendor_id', $vendorId)->count();
        ShopVendor::where('id', $vendorId)->update(['products_count' => $count]);
    }
}
