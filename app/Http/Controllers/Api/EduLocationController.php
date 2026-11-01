<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduLocation;
use App\Models\EduMunicipality;
use App\Models\EduSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Обуки: places trainings are held at. A location made from a school copies
 * its name, municipality and address.
 */
class EduLocationController extends Controller
{
    // GET /api/edu-locations?search&region_id&municipality_id&kind
    public function index(Request $request): JsonResponse
    {
        $query = EduLocation::query()
            ->with(['municipality:id,name,region_id,part_of_skopje', 'school:id,display_name,level'])
            ->orderBy('name')
            ->orderBy('id');

        if (in_array($request->query('kind'), EduLocation::KINDS, true)) {
            $query->where('kind', $request->query('kind'));
        }
        if ($municipalityId = $request->integer('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }
        if ($regionId = $request->integer('region_id')) {
            $query->whereIn('municipality_id', EduMunicipality::select('id')->where('region_id', $regionId));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('contact', 'like', $like));
        }

        return response()->json($query->get());
    }

    // POST /api/edu-locations
    public function store(Request $request): JsonResponse
    {
        $data = $this->fromSchool($this->validatePayload($request));
        $location = EduLocation::create($data);

        return response()->json($this->load($location), 201);
    }

    // PUT /api/edu-locations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $location = EduLocation::findOrFail($id);
        $data = $this->validatePayload($request, $location);
        if (!empty($data['school_id']) && (int) $data['school_id'] !== $location->school_id) {
            $data = $this->fromSchool($data);
        }
        $location->update($data);

        return response()->json($this->load($location->fresh()));
    }

    // DELETE /api/edu-locations/{id}
    public function destroy(int $id): JsonResponse
    {
        $location = EduLocation::findOrFail($id);
        // Groups held there keep the place as free text.
        EduGroup::where('location_id', $location->id)->whereNull('location_text')->update(['location_text' => mb_substr($location->name, 0, 255)]);
        $location->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, ?EduLocation $location = null): array
    {
        $rules = [
            'school_id' => 'nullable|integer|exists:edu_schools,id',
            'name' => 'nullable|string|max:255',
            'kind' => 'nullable|in:' . implode(',', EduLocation::KINDS),
            'municipality_id' => 'nullable|integer|exists:edu_municipalities,id',
            'address' => 'nullable|string|max:2000',
            'contact' => 'nullable|string|max:2000',
            'capacity' => 'nullable|integer|min:1|max:65535',
            'notes' => 'nullable|string|max:5000',
        ];
        if (!$location) {
            $rules['name'] = 'required_without:school_id|nullable|string|max:255';
            $rules['municipality_id'] = 'required_without:school_id|nullable|integer|exists:edu_municipalities,id';
        } else {
            $rules['name'] = 'sometimes|required|string|max:255';
            $rules['municipality_id'] = 'sometimes|required|integer|exists:edu_municipalities,id';
        }

        return $request->validate($rules);
    }

    /** Fills what was left empty from the chosen school. */
    private function fromSchool(array $data): array
    {
        if (empty($data['school_id'])) {
            $data['kind'] ??= 'other';

            return $data;
        }
        $school = EduSchool::findOrFail($data['school_id']);
        $data['name'] = ($data['name'] ?? null) ?: $school->display_name;
        $data['municipality_id'] = ($data['municipality_id'] ?? null) ?: $school->municipality_id;
        $data['address'] = ($data['address'] ?? null) ?: $school->address;
        $data['contact'] = ($data['contact'] ?? null) ?: (implode(', ', $school->phones ?? []) ?: null);
        $data['kind'] = ($data['kind'] ?? null) ?: 'school';

        return $data;
    }

    private function load(EduLocation $location): EduLocation
    {
        return $location->load(['municipality:id,name,region_id,part_of_skopje', 'school:id,display_name,level']);
    }
}
