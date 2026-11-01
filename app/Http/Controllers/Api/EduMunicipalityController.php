<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduMunicipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: municipalities, their region and nearest training centre.
 */
class EduMunicipalityController extends Controller
{
    // GET /api/edu-municipalities?region_id&hubs_only&search
    public function index(Request $request): JsonResponse
    {
        $query = EduMunicipality::query()
            ->with(['region:id,name', 'hub:id,name,hub_short_name'])
            ->withCount('schools')
            ->orderBy('name');

        if ($regionId = $request->integer('region_id')) {
            $query->where('region_id', $regionId);
        }
        if ($request->boolean('hubs_only')) {
            $query->where('is_training_hub', true);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('name_sq', 'like', $like)
                ->orWhere('aliases', 'like', $like));
        }

        return response()->json($query->get());
    }

    // POST /api/edu-municipalities
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $municipality = DB::transaction(function () use ($data) {
            $municipality = EduMunicipality::create($data);
            if ($municipality->is_training_hub) {
                $municipality->update(['hub_municipality_id' => $municipality->id]);
            }

            return $municipality;
        });

        return response()->json($this->load($municipality), 201);
    }

    // PUT /api/edu-municipalities/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $municipality = EduMunicipality::findOrFail($id);
        $data = $this->validatePayload($request, $municipality);

        if (array_key_exists('is_training_hub', $data) && !$data['is_training_hub'] && $municipality->is_training_hub) {
            $served = $municipality->served()->whereKeyNot($municipality->id)->count();
            if ($served) {
                return response()->json([
                    'message' => "Општината е центар за обука за {$served} други општини. Прво доделете им друг центар.",
                ], 409);
            }
            if (($data['hub_municipality_id'] ?? $municipality->hub_municipality_id) === $municipality->id) {
                $data['hub_municipality_id'] = null;
            }
        }
        if (($data['is_training_hub'] ?? $municipality->is_training_hub) === true) {
            $data['hub_municipality_id'] = $municipality->id;
        }

        $municipality->update($data);

        return response()->json($this->load($municipality->fresh()));
    }

    // DELETE /api/edu-municipalities/{id}
    public function destroy(int $id): JsonResponse
    {
        $municipality = EduMunicipality::findOrFail($id);

        $reasons = [];
        if ($n = $municipality->schools()->count()) {
            $reasons[] = "{$n} училишта";
        }
        if ($n = $municipality->locations()->count()) {
            $reasons[] = "{$n} локации";
        }
        if ($n = $municipality->served()->whereKeyNot($municipality->id)->count()) {
            $reasons[] = "е центар за {$n} општини";
        }
        if ($reasons) {
            return response()->json([
                'message' => 'Општината не може да се избрише: ' . implode(', ', $reasons) . '.',
            ], 409);
        }

        $municipality->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, ?EduMunicipality $municipality = null): array
    {
        $req = $municipality ? 'sometimes|required' : 'required';
        $unique = 'unique:edu_municipalities,name' . ($municipality ? ",{$municipality->id}" : '');

        $data = $request->validate([
            'name' => "{$req}|string|max:100|{$unique}",
            'name_sq' => 'nullable|string|max:100',
            'region_id' => "{$req}|integer|exists:edu_regions,id",
            'is_training_hub' => 'sometimes|boolean',
            'hub_municipality_id' => 'nullable|integer|exists:edu_municipalities,id',
            'hub_short_name' => 'nullable|string|max:30',
            'part_of_skopje' => 'sometimes|boolean',
            'aliases' => 'nullable|array',
            'aliases.*' => 'nullable|string|max:100',
        ]);

        // The nearest centre must be a centre (a centre points at itself).
        $hubId = $data['hub_municipality_id'] ?? null;
        if ($hubId && $hubId !== $municipality?->id && !EduMunicipality::whereKey($hubId)->where('is_training_hub', true)->exists()) {
            throw ValidationException::withMessages([
                'hub_municipality_id' => 'Избраната општина не е центар за обука.',
            ]);
        }
        if (array_key_exists('aliases', $data)) {
            $data['aliases'] = array_values(array_unique(array_filter(array_map(fn ($a) => trim((string) $a), $data['aliases'] ?? [])))) ?: null;
        }

        return $data;
    }

    private function load(EduMunicipality $municipality): EduMunicipality
    {
        return $municipality->load(['region:id,name', 'hub:id,name,hub_short_name'])->loadCount('schools');
    }
}
