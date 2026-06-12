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
            'name_en' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'description_en' => 'nullable|string',
            'details' => 'nullable|array',
            'details_en' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'bullets' => 'nullable|array',
            'bullets.*.text' => 'required_with:bullets|string',
            'bullets.*.text_en' => 'nullable|string',
            'bullets.*.sort_order' => 'nullable|integer',
        ]);

        $service = Service::create(collect($validated)->except('bullets')->toArray());

        if (! empty($validated['bullets'])) {
            foreach ($validated['bullets'] as $index => $bullet) {
                $service->bullets()->create([
                    'text' => $bullet['text'],
                    'text_en' => $bullet['text_en'] ?? null,
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
            'name_en' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'description_en' => 'nullable|string',
            'details' => 'nullable|array',
            'details_en' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'bullets' => 'nullable|array',
            'bullets.*.text' => 'required_with:bullets|string',
            'bullets.*.text_en' => 'nullable|string',
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
                        'text_en' => $bullet['text_en'] ?? null,
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
     * GET /api/services/public?locale=xx (PUBLIC)
     * Get services for frontend display, localized. Macedonian is the base;
     * for other locales each field uses its *_en value when present, otherwise
     * falls back to Macedonian. The response keeps the same shape the frontend
     * already consumes (name/description/details/bullets[].text).
     */
    public function public(Request $request): JsonResponse
    {
        $en = $request->query('locale', 'mk') === 'en';

        $services = Service::with('bullets')
            ->orderBy('sort_order')
            ->get()
            ->map(function (Service $s) use ($en) {
                return [
                    'id' => $s->id,
                    'name' => $en && $s->name_en ? $s->name_en : $s->name,
                    'color' => $s->color,
                    'description' => $en && $s->description_en ? $s->description_en : $s->description,
                    'details' => $en && $s->details_en ? $s->details_en : $s->details,
                    'sort_order' => $s->sort_order,
                    'bullets' => $s->bullets->map(fn ($b) => [
                        'id' => $b->id,
                        'text' => $en && $b->text_en ? $b->text_en : $b->text,
                        'sort_order' => $b->sort_order,
                    ])->values(),
                ];
            });

        return response()->json($services);
    }
}
