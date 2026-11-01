<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduMunicipality;
use App\Models\EduProgram;
use App\Models\EduProgramZone;
use App\Models\EduProgramZoneMunicipality;
use App\Services\Edu\ProgramZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: grouping zones of a program (step 6). They start as a copy of the
 * regions and can be changed per program, also per language.
 */
class EduZoneController extends Controller
{
    private const LANGUAGES = '*,mk,sq,tr';

    public function __construct(private ProgramZoneService $zones)
    {
    }

    // GET /api/edu-programs/{id}/zones
    public function index(int $id): JsonResponse
    {
        return response()->json($this->zones->overview(EduProgram::findOrFail($id)));
    }

    // POST /api/edu-programs/{id}/zones/reset  body: {confirm: true}
    public function reset(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $request->validate(['confirm' => 'accepted']);
        $this->zones->reset($program);

        return response()->json($this->zones->overview($program));
    }

    // POST /api/edu-programs/{id}/zones  body: {name, municipality_ids?, language?}
    public function store(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'municipality_ids' => 'nullable|array|max:200',
            'municipality_ids.*' => 'integer|distinct',
            'language' => 'nullable|in:' . self::LANGUAGES,
        ]);
        $ids = array_map('intval', $data['municipality_ids'] ?? []);
        if ($ids && ($missing = array_diff($ids, EduMunicipality::whereIn('id', $ids)->pluck('id')->all()))) {
            throw ValidationException::withMessages(['municipality_ids' => 'Непостоечки општини: ' . implode(', ', $missing) . '.']);
        }
        $zone = $this->zones->store($program, trim($data['name']), $ids, $data['language'] ?? EduProgramZoneMunicipality::ALL);

        return response()->json($this->zoneOut($program, $zone->id), 201);
    }

    // PUT /api/edu-zones/{id}  body: {name?, sort_order?}
    public function update(Request $request, int $id): JsonResponse
    {
        $zone = EduProgramZone::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
        ]);
        $zone->update($data);

        return response()->json($this->zoneOut($zone->program, $zone->id));
    }

    // POST /api/edu-zones/{id}/merge  body: {into_zone_id}
    public function merge(Request $request, int $id): JsonResponse
    {
        $from = EduProgramZone::findOrFail($id);
        $data = $request->validate(['into_zone_id' => 'required|integer']);
        $into = EduProgramZone::where('program_id', $from->program_id)->find($data['into_zone_id']);
        if (!$into || $into->id === $from->id) {
            throw ValidationException::withMessages(['into_zone_id' => 'Изберете друга зона од истата програма.']);
        }
        $this->zones->merge($from, $into);

        return response()->json($this->zoneOut($into->program, $into->id));
    }

    // PUT /api/edu-programs/{id}/zones/assign  body: {municipality_id, zone_id, language?}
    public function assign(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'municipality_id' => 'required|integer|exists:edu_municipalities,id',
            'zone_id' => 'required|integer',
            'language' => 'nullable|in:' . self::LANGUAGES,
        ]);
        $zone = EduProgramZone::where('program_id', $program->id)->find($data['zone_id']);
        if (!$zone) {
            throw ValidationException::withMessages(['zone_id' => 'Зоната не е од оваа програма.']);
        }

        return response()->json($this->zones->assign($program, (int) $data['municipality_id'], $zone, $data['language'] ?? EduProgramZoneMunicipality::ALL));
    }

    // DELETE /api/edu-zones/{id}
    public function destroy(int $id): JsonResponse
    {
        $zone = EduProgramZone::findOrFail($id);
        if ($n = EduProgramZoneMunicipality::where('zone_id', $zone->id)->count()) {
            return response()->json(['message' => "Зоната не е празна ({$n} општини). Преместете ги или спојте ја со друга зона."], 409);
        }
        // Groups of the zone keep existing without a zone.
        $zone->delete();

        return response()->json(null, 204);
    }

    private function zoneOut(EduProgram $program, int $zoneId): ?array
    {
        return $this->zones->overview($program)->firstWhere('id', $zoneId);
    }
}
