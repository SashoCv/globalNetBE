<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewVendorApplicationAdminMail;
use App\Models\AdminNotification;
use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PublicShopController extends Controller
{
    // GET /api/public/shop-categories
    public function categories(): JsonResponse
    {
        $cats = ShopCategory::query()
            ->where('is_active', true)
            ->withCount(['products as products_count' => function ($q) {
                $q->where('status', 'active')
                  ->whereHas('vendor', fn ($qq) => $qq->where('status', 'active'));
            }])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($cats->map(fn ($c) => [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'description' => $c->description,
            'icon' => $c->icon,
            'color' => $c->color,
            'type' => $c->kind, // map to FE convention
            'productCount' => (int) ($c->products_count ?? 0),
        ]));
    }

    // GET /api/public/shop-vendors
    public function vendors(): JsonResponse
    {
        $vendors = ShopVendor::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($vendors->map(fn ($v) => [
            'id' => $v->id,
            'slug' => $v->slug,
            'name' => $v->name,
            'logo' => $v->logo,
            'description' => $v->description,
            'city' => $v->city,
            'address' => $v->address,
            'phone' => $v->phone,
            'email' => $v->email,
            'website' => $v->website,
            'productCount' => (int) ($v->products_count ?? 0),
        ]));
    }

    // POST /api/public/shop-vendors  — public vendor signup
    public function storeVendor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edb' => 'nullable|string|max:32',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:shop_vendors,email',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:500',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:shop_categories,id',
        ]);

        $categoryIds = $validated['category_ids'] ?? [];
        unset($validated['category_ids']);

        $validated['status'] = 'pending';
        $validated['sort_order'] = (int) ShopVendor::max('sort_order') + 1;

        $vendor = ShopVendor::create($validated);

        if (!empty($categoryIds)) {
            $vendor->categories()->sync($categoryIds);
        }

        try {
            AdminNotification::notify(
                type: 'new_vendor_application',
                title: 'Нова апликација за добавувач',
                body: "{$vendor->name} аплицираше да стане добавувач и чека одобрување.",
                data: ['vendor_id' => $vendor->id],
                link: '/admin/shop-vendors',
            );
        } catch (\Throwable $e) {
            Log::error('[vendor apply] notification failed', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
        }

        $adminEmail = config('app.shop_admin_email');
        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new NewVendorApplicationAdminMail($vendor));
            } catch (\Throwable $e) {
                Log::error('[vendor apply] mail failed', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'Барањето е примено. Ќе ве известиме по одобрување.',
            'id' => $vendor->id,
        ], 201);
    }

    // GET /api/public/shop-vendors/{id} — vendor detail + paginated products
    public function vendor(Request $request, int $id): JsonResponse
    {
        $vendor = ShopVendor::query()
            ->where('id', $id)
            ->where('status', 'active')
            ->firstOrFail();

        $perPage  = min((int) $request->query('per_page', 15), 60);
        $paginated = ShopProduct::query()
            ->where('shop_vendor_id', $vendor->id)
            ->where('status', 'active')
            ->with('category:id,name,slug,kind,color')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        $mapProduct = fn ($p) => [
            'id'               => $p->id,
            'slug'             => $p->slug,
            'name'             => $p->name,
            'sku'              => $p->sku,
            'price'            => $p->price !== null ? (float) $p->price : null,
            'currency'         => $p->currency,
            'image'            => $p->image,
            'shortDescription' => $p->short_description,
            'type'             => $p->kind,
            'isFeatured'       => (bool) $p->is_featured,
            'stock'            => $p->stock,
            'vendorId'         => $vendor->id,
            'vendorSlug'       => $vendor->slug,
            'vendorName'       => $vendor->name,
            'vendorLogo'       => $vendor->logo,
            'categorySlug'     => $p->category?->slug,
            'categoryName'     => $p->category?->name,
            'categoryColor'    => $p->category?->color,
        ];

        return response()->json([
            'vendor' => [
                'id'           => $vendor->id,
                'slug'         => $vendor->slug,
                'name'         => $vendor->name,
                'logo'         => $vendor->logo,
                'description'  => $vendor->description,
                'city'         => $vendor->city,
                'address'      => $vendor->address,
                'phone'        => $vendor->phone,
                'email'        => $vendor->email,
                'website'      => $vendor->website,
                'productCount' => $paginated->total(),
            ],
            'products'     => $paginated->map($mapProduct)->values(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
        ]);
    }

    // GET /api/public/shop-products (paginated, filtered)
    public function products(Request $request): JsonResponse
    {
        $query = ShopProduct::query()
            ->where('status', 'active')
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'))
            ->with([
                'vendor:id,name,slug,logo',
                'category:id,name,slug,kind,color',
            ]);

        // Search
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('sku', 'like', $like)
                  ->orWhere('short_description', 'like', $like);
            });
        }

        // Categories (by slug, comma-separated or array)
        if ($cats = $request->query('categories')) {
            $slugs = is_array($cats) ? $cats : array_filter(explode(',', $cats));
            if (!empty($slugs)) {
                $query->whereHas('category', fn ($q) => $q->whereIn('slug', $slugs));
            }
        }

        // Vendors (by slug)
        if ($vendors = $request->query('vendors')) {
            $slugs = is_array($vendors) ? $vendors : array_filter(explode(',', $vendors));
            if (!empty($slugs)) {
                $query->whereHas('vendor', fn ($q) => $q->whereIn('slug', $slugs));
            }
        }

        // Kind: product | service
        if ($kind = $request->query('type')) {
            if (in_array($kind, ['product', 'service'], true)) {
                $query->where('kind', $kind);
            }
        }

        // Featured-only
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        // Price range
        if ($min = $request->query('priceMin')) {
            $query->where('price', '>=', (float) $min);
        }
        if ($max = $request->query('priceMax')) {
            $query->where('price', '<=', (float) $max);
        }

        // Sort
        switch ($request->query('sort')) {
            case 'price-asc':  $query->orderBy('price', 'asc'); break;
            case 'price-desc': $query->orderBy('price', 'desc'); break;
            case 'name':       $query->orderBy('name', 'asc'); break;
            default:
                $query->orderByDesc('is_featured')
                      ->orderBy('sort_order')
                      ->orderBy('id');
                break;
        }

        $perPage = min(max((int) $request->query('per_page', 24), 6), 100);
        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(fn ($p) => $this->productPayload($p));

        return response()->json($paginated);
    }

    // GET /api/public/shop-products/{id}
    // Lookup by ID — slug isn't globally unique (two different vendors can sell the same item).
    public function product(int $id): JsonResponse
    {
        $product = ShopProduct::query()
            ->where('id', $id)
            ->where('status', 'active')
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'))
            ->with([
                'vendor:id,name,slug,logo,description,city,phone,email,website',
                'category:id,name,slug,kind,color',
            ])
            ->firstOrFail();

        return response()->json($this->productPayload($product, true));
    }

    private function productPayload(ShopProduct $p, bool $verbose = false): array
    {
        $base = [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => $p->price !== null ? (float) $p->price : null,
            'currency' => $p->currency,
            'image' => $p->image,
            'shortDescription' => $p->short_description,
            'type' => $p->kind,
            'isFeatured' => (bool) $p->is_featured,
            'stock' => $p->stock,
            'vendorId' => $p->shop_vendor_id,
            'vendorSlug' => $p->vendor?->slug,
            'vendorName' => $p->vendor?->name,
            'vendorLogo' => $p->vendor?->logo,
            'categorySlug' => $p->category?->slug,
            'categoryName' => $p->category?->name,
            'categoryColor' => $p->category?->color,
        ];
        if ($verbose) {
            $base['description'] = $p->description;
            $base['vendorDescription'] = $p->vendor?->description;
            $base['vendorCity'] = $p->vendor?->city;
            $base['vendorPhone'] = $p->vendor?->phone;
            $base['vendorEmail'] = $p->vendor?->email;
            $base['vendorWebsite'] = $p->vendor?->website;
        }
        return $base;
    }
}
