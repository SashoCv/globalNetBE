<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroupSchool;
use App\Models\EduMunicipality;
use App\Models\EduParticipant;
use App\Models\EduProgramSchool;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Services\Edu\EduText;
use App\Support\Edu\EduLanguage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: the shared database of primary and secondary schools.
 */
class EduSchoolController extends Controller
{
    private const SORTS = ['display_name', 'name_key', 'level', 'source_no', 'created_at', 'id'];

    // GET /api/edu-schools/stats?level
    public function stats(Request $request): JsonResponse
    {
        $base = fn () => EduSchool::query()->when(
            in_array($request->query('level'), EduSchool::LEVELS, true),
            fn ($q) => $q->where('level', $request->query('level')),
        );

        $byLevel = $base()->selectRaw('level, COUNT(*) as cnt')->groupBy('level')->pluck('cnt', 'level');
        $byRegion = $base()
            ->join('edu_municipalities', 'edu_municipalities.id', '=', 'edu_schools.municipality_id')
            ->selectRaw('edu_municipalities.region_id, COUNT(*) as cnt')
            ->groupBy('edu_municipalities.region_id')
            ->pluck('cnt', 'region_id');

        return response()->json([
            'total' => (int) $byLevel->sum(),
            'by_level' => collect(EduSchool::LEVELS)->mapWithKeys(fn ($l) => [$l => (int) ($byLevel[$l] ?? 0)]),
            'by_region' => EduRegion::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($r) => ['region_id' => $r->id, 'name' => $r->name, 'count' => (int) ($byRegion[$r->id] ?? 0)]),
            'unknown_language' => $base()->whereJsonLength('languages', 0)->count(),
        ]);
    }

    // GET /api/edu-schools?level&region_id&municipality_id&language&search&sort&direction&per_page&page&all=1
    public function index(Request $request): JsonResponse
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'display_name';
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = EduSchool::query()
            ->with('municipality:id,name,name_sq,region_id,part_of_skopje')
            ->orderBy($sort, $direction)
            ->orderBy('id');
        $this->filter($query, $request);

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));

        return response()->json($query->paginate($perPage));
    }

    // GET /api/edu-schools/{id}
    public function show(int $id): JsonResponse
    {
        $school = EduSchool::with(['municipality.region:id,name', 'municipality.hub:id,name', 'locations:id,school_id,name,kind'])->findOrFail($id);

        $programs = EduProgramSchool::query()
            ->join('edu_programs', 'edu_programs.id', '=', 'edu_program_schools.program_id')
            ->where('edu_program_schools.school_id', $school->id)
            ->orderByDesc('edu_programs.created_at')
            ->get([
                'edu_program_schools.program_id', 'edu_programs.title', 'edu_programs.status', 'edu_programs.period_label',
                'edu_program_schools.count_mk', 'edu_program_schools.count_sq', 'edu_program_schools.count_tr', 'edu_program_schools.count_total',
            ])
            ->map(fn ($row) => [
                'program_id' => (int) $row->program_id,
                'title' => $row->title,
                'status' => $row->status,
                'period_label' => $row->period_label,
                'count_mk' => (int) $row->count_mk,
                'count_sq' => (int) $row->count_sq,
                'count_tr' => (int) $row->count_tr,
                'count_total' => (int) $row->count_total,
            ])->values();

        return response()->json($school->toArray() + ['programs' => $programs]);
    }

    // POST /api/edu-schools
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $school = EduSchool::create($data);

        return response()->json($school->load('municipality:id,name,name_sq,region_id,part_of_skopje'), 201);
    }

    // PUT /api/edu-schools/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $school = EduSchool::findOrFail($id);
        $data = $this->validatePayload($request, $school);
        $school->update($data);

        return response()->json($school->fresh()->load('municipality:id,name,name_sq,region_id,part_of_skopje'));
    }

    // DELETE /api/edu-schools/{id}
    public function destroy(int $id): JsonResponse
    {
        // Locations made from the school keep their copy (school_id → null).
        $school = EduSchool::findOrFail($id);
        $programIds = EduProgramSchool::where('school_id', $school->id)->pluck('program_id')
            ->merge(EduParticipant::where('school_id', $school->id)->pluck('program_id'))
            ->merge(EduGroupSchool::where('school_id', $school->id)->join('edu_groups', 'edu_groups.id', '=', 'edu_group_schools.group_id')->pluck('edu_groups.program_id'))
            ->unique();
        if ($programIds->isNotEmpty()) {
            return response()->json([
                'message' => 'Училиштето не може да се избрише: се користи во ' . $programIds->count() . ' ' . ($programIds->count() === 1 ? 'програма' : 'програми') . '.',
                'program_ids' => $programIds->values(),
            ], 409);
        }
        $school->delete();

        return response()->json(null, 204);
    }

    private function filter(Builder $query, Request $request): void
    {
        if (in_array($request->query('level'), EduSchool::LEVELS, true)) {
            $query->where('level', $request->query('level'));
        }
        if ($municipalityId = $request->integer('municipality_id')) {
            $query->where('municipality_id', $municipalityId);
        }
        if ($regionId = $request->integer('region_id')) {
            $query->whereIn('municipality_id', EduMunicipality::select('id')->where('region_id', $regionId));
        }
        $language = $request->query('language');
        if ($language === 'unknown') {
            $query->whereJsonLength('languages', 0);
        } elseif (EduLanguage::isValid($language)) {
            $query->whereJsonContains('languages', $language);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $keyLike = '%' . EduText::words(EduText::fixHomoglyphs($search)) . '%';
            $query->where(fn ($q) => $q->where('display_name', 'like', $like)
                ->orWhere('name_key', 'like', $keyLike)
                ->orWhere('address', 'like', $like)
                ->orWhere('settlement', 'like', $like)
                ->orWhere('email_raw', 'like', $like)
                ->orWhere('director_name', 'like', $like)
                ->orWhere('schools_mk_email', 'like', $like));
        }
    }

    private function validatePayload(Request $request, ?EduSchool $school = null): array
    {
        $req = $school ? 'sometimes|required' : 'required';
        $data = $request->validate([
            'level' => "{$req}|in:" . implode(',', EduSchool::LEVELS),
            'display_name' => "{$req}|string|max:255",
            'municipality_id' => "{$req}|integer|exists:edu_municipalities,id",
            'source_no' => 'nullable|integer|min:0',
            'address' => 'nullable|string|max:2000',
            'address_sq' => 'nullable|string|max:2000',
            'languages' => 'nullable|array',
            'languages.*' => 'in:' . implode(',', EduLanguage::CODES),
            'phones' => 'nullable|array',
            'phones.*' => 'string|max:50',
            'phone_raw' => 'nullable|string|max:2000',
            'director_name' => 'nullable|string|max:255',
            'director_contact' => 'nullable|string|max:2000',
            'emails' => 'nullable|array',
            'emails.*' => 'email|max:255',
            'schools_mk_email' => 'nullable|email|max:255',
            'comment' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
        ]);

        foreach (['languages', 'phones', 'emails'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = $data[$list] ?? [];
            }
        }
        if (isset($data['languages'])) {
            $data['languages'] = EduLanguage::normalize($data['languages']);
        }
        if (isset($data['emails'])) {
            $data['emails'] = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $data['emails'])));
        }
        if (isset($data['phones'])) {
            $data['phones'] = array_values(array_unique(array_filter(array_map('trim', $data['phones']))));
        }
        if (isset($data['schools_mk_email'])) {
            $data['schools_mk_email'] = mb_strtolower(trim($data['schools_mk_email']));
        }

        // The name fields follow display_name and the municipality.
        $level = $data['level'] ?? $school?->level;
        $municipalityId = (int) ($data['municipality_id'] ?? $school?->municipality_id);
        $displayName = $data['display_name'] ?? $school?->display_name;
        if (!$school || isset($data['display_name']) || isset($data['municipality_id']) || isset($data['level'])) {
            $municipalityName = EduMunicipality::whereKey($municipalityId)->value('name');
            $names = EduSchool::nameFields($displayName, $municipalityName);
            if ($names['name_key'] === '') {
                throw ValidationException::withMessages(['display_name' => 'Внесете име на училиштето.']);
            }

            $taken = fn (string $key) => EduSchool::query()
                ->where('level', $level)
                ->where('municipality_id', $municipalityId)
                ->where('name_key', $key)
                ->when($school, fn ($q) => $q->whereKeyNot($school->id))
                ->first();

            // Same name, different type prefix (СОУ / СОМУ „Гостивар“): keep the prefix in the key.
            if (($other = $taken($names['name_key'])) && !EduText::samePrefixFamily($other->type_prefix, $names['type_prefix'])) {
                $names['name_key'] = EduText::keyWithPrefix($names['name'], $municipalityName);
                $other = $taken($names['name_key']);
            }
            if ($other) {
                throw ValidationException::withMessages([
                    'display_name' => "Училиштето веќе постои во оваа општина: „{$other->display_name}“.",
                ]);
            }
            $data = array_merge($data, $names);
        }

        return $data;
    }
}
