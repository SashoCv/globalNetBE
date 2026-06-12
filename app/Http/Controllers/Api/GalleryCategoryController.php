<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GalleryCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryCategoryController extends Controller
{
    /**
     * GET /api/gallery-categories (admin)
     * Full list for management (both locales).
     */
    public function index(): JsonResponse
    {
        return response()->json(
            GalleryCategory::orderBy('sort_order')->orderBy('id')->get()
        );
    }

    /**
     * POST /api/gallery-categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'name_en' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        $category = GalleryCategory::create($validated);

        return response()->json($category, 201);
    }

    /**
     * PUT /api/gallery-categories/{id}
     * Labels and order are editable; the slug stays fixed.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = GalleryCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'name_en' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * DELETE /api/gallery-categories/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        GalleryCategory::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/gallery-categories/public?locale=xx (PUBLIC)
     * Localized list for the public gallery tabs.
     */
    public function public(Request $request): JsonResponse
    {
        $en = $request->query('locale', 'mk') === 'en';

        $categories = GalleryCategory::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GalleryCategory $c) => [
                'slug' => $c->slug,
                'name' => $en && $c->name_en ? $c->name_en : $c->name,
            ]);

        return response()->json($categories);
    }
}
