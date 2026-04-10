<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HcClinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HcClinicController extends Controller
{
    /**
     * GET /api/hc-clinics
     * List clinics with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = HcClinic::query()->latest();

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $clinics = $query->get();

        return response()->json($clinics);
    }

    /**
     * POST /api/hc-clinics
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'specialties' => 'required|string|max:500',
            'phone' => 'required|string|max:50',
            'address' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $clinic = HcClinic::create($validated);

        return response()->json($clinic, 201);
    }

    /**
     * PUT /api/hc-clinics/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $clinic = HcClinic::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'specialties' => 'sometimes|required|string|max:500',
            'phone' => 'sometimes|required|string|max:50',
            'address' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $clinic->update($validated);

        return response()->json($clinic);
    }

    /**
     * DELETE /api/hc-clinics/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $clinic = HcClinic::findOrFail($id);
        $clinic->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/hc-clinics/public (PUBLIC)
     * Get active clinics for frontend.
     */
    public function public(Request $request): JsonResponse
    {
        $query = HcClinic::where('active', true);

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        $clinics = $query->get();

        return response()->json($clinics);
    }
}
