<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HcHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HcHospitalController extends Controller
{
    /**
     * GET /api/hc-hospitals
     * List all hospitals.
     */
    public function index(): JsonResponse
    {
        $hospitals = HcHospital::latest()->get();

        return response()->json($hospitals);
    }

    /**
     * POST /api/hc-hospitals
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'description' => 'nullable|string',
            'specialties' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:500',
            'active' => 'nullable|boolean',
        ]);

        $hospital = HcHospital::create($validated);

        return response()->json($hospital, 201);
    }

    /**
     * PUT /api/hc-hospitals/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $hospital = HcHospital::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'specialties' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:500',
            'active' => 'nullable|boolean',
        ]);

        $hospital->update($validated);

        return response()->json($hospital);
    }

    /**
     * DELETE /api/hc-hospitals/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $hospital = HcHospital::findOrFail($id);
        $hospital->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/hc-hospitals/public (PUBLIC)
     * Get active hospitals for frontend.
     */
    public function public(): JsonResponse
    {
        $hospitals = HcHospital::where('active', true)->get();

        return response()->json($hospitals);
    }
}
