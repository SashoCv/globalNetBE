<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduRegion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Обуки: regions (with their municipalities).
 */
class EduRegionController extends Controller
{
    // GET /api/edu-regions
    public function index(): JsonResponse
    {
        $regions = EduRegion::query()
            ->withCount('municipalities')
            ->with(['municipalities' => fn ($q) => $q->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($regions);
    }

    // POST /api/edu-regions
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:edu_regions,name',
            'sort_order' => 'nullable|integer',
        ]);
        $data['sort_order'] ??= (int) EduRegion::max('sort_order') + 1;

        return response()->json(EduRegion::create($data), 201);
    }

    // PUT /api/edu-regions/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $region = EduRegion::findOrFail($id);
        $data = $request->validate([
            'name' => "sometimes|required|string|max:100|unique:edu_regions,name,{$id}",
            'sort_order' => 'sometimes|integer',
        ]);
        $region->update($data);

        return response()->json($region->fresh());
    }

    // DELETE /api/edu-regions/{id}
    public function destroy(int $id): JsonResponse
    {
        $region = EduRegion::findOrFail($id);
        if ($count = $region->municipalities()->count()) {
            return response()->json([
                'message' => "Регионот има {$count} општини. Прво преместете ги во друг регион.",
            ], 409);
        }
        $region->delete();

        return response()->json(null, 204);
    }
}
