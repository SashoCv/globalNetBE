<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceBullet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * GET /api/services
     * List all services with bullets, ordered by sort_order.
     */
    public function index(): JsonResponse
    {
        $services = Service::with('bullets')
            ->orderBy('sort_order')
            ->get();

        return response()->json($services);
    }

    /**
     * POST /api/services
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'bullets' => 'nullable|array',
            'bullets.*.text' => 'required_with:bullets|string',
            'bullets.*.sort_order' => 'nullable|integer',
        ]);

        $service = Service::create(collect($validated)->except('bullets')->toArray());

        if (! empty($validated['bullets'])) {
            foreach ($validated['bullets'] as $index => $bullet) {
                $service->bullets()->create([
                    'text' => $bullet['text'],
                    'sort_order' => $bullet['sort_order'] ?? $index,
                ]);
            }
        }

        return response()->json($service->load('bullets'), 201);
    }

    /**
     * PUT /api/services/{id}
     * Update service including bullets (sync approach).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'bullets' => 'nullable|array',
            'bullets.*.text' => 'required_with:bullets|string',
            'bullets.*.sort_order' => 'nullable|integer',
        ]);

        $service->update(collect($validated)->except('bullets')->toArray());

        // Sync bullets if provided
        if (array_key_exists('bullets', $validated)) {
            $service->bullets()->delete();

            if (! empty($validated['bullets'])) {
                foreach ($validated['bullets'] as $index => $bullet) {
                    $service->bullets()->create([
                        'text' => $bullet['text'],
                        'sort_order' => $bullet['sort_order'] ?? $index,
                    ]);
                }
            }
        }

        return response()->json($service->load('bullets'));
    }

    /**
     * DELETE /api/services/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->delete(); // cascades to bullets via FK

        return response()->json(null, 204);
    }

    /**
     * GET /api/services/public (PUBLIC)
     * Get services for frontend display.
     */
    public function public(): JsonResponse
    {
        $services = Service::with('bullets')
            ->orderBy('sort_order')
            ->get();

        return response()->json($services);
    }
}
