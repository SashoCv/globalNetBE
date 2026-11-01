<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZoneMunicipality;
use App\Models\EduSchool;
use App\Services\Edu\EduProgramCounts;
use App\Services\Edu\EduText;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: the schools of a program with their participant counts per
 * language (step 5 without a list of people). In the "persons" mode the
 * counts come from the participants and only comments can be edited here.
 */
class EduProgramSchoolController extends Controller
{
    public function __construct(private EduProgramCounts $counts)
    {
    }

    // GET /api/edu-programs/{id}/schools?region_id&zone_id&search
    public function index(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $query = EduProgramSchool::query()
            ->where('program_id', $program->id)
            ->with(['school:id,level,display_name,municipality_id,languages', 'school.municipality:id,name,region_id,hub_municipality_id']);

        if ($regionId = $request->integer('region_id')) {
            $query->whereHas('school.municipality', fn ($q) => $q->where('region_id', $regionId));
        }
        if ($zoneId = $request->integer('zone_id')) {
            $municipalityIds = EduProgramZoneMunicipality::where('program_id', $program->id)->where('zone_id', $zoneId)->pluck('municipality_id');
            $query->whereHas('school', fn ($q) => $q->whereIn('municipality_id', $municipalityIds));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $keyLike = '%' . EduText::words(EduText::fixHomoglyphs($search)) . '%';
            $query->whereHas('school', fn ($q) => $q->where('display_name', 'like', '%' . $search . '%')->orWhere('name_key', 'like', $keyLike));
        }

        $allocated = $this->counts->allocated($program);

        return response()->json($query->get()
            ->sortBy(fn ($row) => [$row->school?->municipality?->name, $row->school?->display_name, $row->id])
            ->map(fn (EduProgramSchool $row) => $this->item($row, $allocated[$row->school_id] ?? []))
            ->values());
    }

    // PUT /api/edu-programs/{id}/schools  body: {items: [{school_id, count_mk?, count_sq?, count_tr?, comment?}]}
    public function bulkUpsert(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:1000',
            'items.*.school_id' => 'required|integer|distinct',
            'items.*.count_mk' => 'sometimes|integer|min:0|max:65535',
            'items.*.count_sq' => 'sometimes|integer|min:0|max:65535',
            'items.*.count_tr' => 'sometimes|integer|min:0|max:65535',
            'items.*.comment' => 'sometimes|nullable|string|max:2000',
        ]);
        $items = collect($data['items'])->keyBy(fn ($item) => (int) $item['school_id']);

        // One query for every school: they must exist and be of the program's level.
        $schools = EduSchool::whereIn('id', $items->keys())->get(['id', 'level', 'display_name'])->keyBy('id');
        if ($missing = $items->keys()->diff($schools->keys())->all()) {
            throw ValidationException::withMessages(['items' => 'Непостоечки училишта: ' . implode(', ', $missing) . '.']);
        }
        $wrongLevel = $schools->where('level', '!=', $program->school_level);
        if ($wrongLevel->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Училиштата не се од нивото на програмата: ' . $wrongLevel->pluck('display_name')->implode(', ') . '.',
            ]);
        }
        $countKeys = ['count_mk', 'count_sq', 'count_tr'];
        if ($program->isPersons() && $items->contains(fn ($item) => array_intersect_key($item, array_flip($countKeys)))) {
            throw ValidationException::withMessages(['items' => 'Програмата има листа на учесници: бројките се пресметуваат од учесниците.']);
        }

        $existing = EduProgramSchool::where('program_id', $program->id)->whereIn('school_id', $items->keys())->get()->keyBy('school_id');
        $allocated = $this->counts->allocated($program);
        $short = [];
        foreach ($items as $schoolId => $item) {
            foreach (EduLanguage::CODES as $lang) {
                $value = $item['count_' . $lang] ?? $existing->get($schoolId)?->{'count_' . $lang} ?? 0;
                if (($allocated[$schoolId][$lang] ?? 0) > $value) {
                    $short[] = "{$schools[$schoolId]->display_name} ({$allocated[$schoolId][$lang]} " . EduLanguage::LABELS[$lang] . ')';
                }
            }
        }
        if ($short) {
            throw ValidationException::withMessages([
                'items' => 'Бројката е помала од веќе распоредените во групи: ' . implode(', ', $short) . '.',
            ]);
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        DB::transaction(function () use ($items, $existing, $program, &$result) {
            foreach ($items as $schoolId => $item) {
                $row = $existing->get($schoolId) ?? new EduProgramSchool([
                    'program_id' => $program->id,
                    'school_id' => $schoolId,
                    'source' => $program->isPersons() ? 'persons' : 'manual',
                ]);
                $isNew = !$row->exists;
                $row->fill(collect($item)->only(['count_mk', 'count_sq', 'count_tr', 'comment'])->all());
                if ($row->isDirty(['count_mk', 'count_sq', 'count_tr']) && !$program->isPersons()) {
                    $row->source = 'manual';
                }
                if ($isNew || $row->isDirty()) {
                    $row->save();
                    $result[$isNew ? 'created' : 'updated']++;
                } else {
                    $result['unchanged']++;
                }
            }
        });

        return response()->json($result);
    }

    // DELETE /api/edu-programs/{id}/schools/{schoolId}
    public function destroy(int $id, int $schoolId): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $row = EduProgramSchool::where('program_id', $program->id)->where('school_id', $schoolId)->firstOrFail();

        $inGroups = EduGroupSchool::whereIn('group_id', EduGroup::select('id')->where('program_id', $program->id))
            ->where('school_id', $schoolId)->exists();
        if ($inGroups) {
            return response()->json(['message' => 'Училиштето е распоредено во групи. Прво отстранете го од групите.'], 409);
        }
        if ($n = EduParticipant::where('program_id', $program->id)->where('school_id', $schoolId)->count()) {
            return response()->json(['message' => "Училиштето има {$n} учесници во програмата. Прво избришете ги или преместете ги."], 409);
        }
        $row->delete();

        return response()->json(null, 204);
    }

    private function item(EduProgramSchool $row, array $allocated): array
    {
        $alloc = ['mk' => 0, 'sq' => 0, 'tr' => 0];
        $remaining = [];
        foreach (EduLanguage::CODES as $lang) {
            $alloc[$lang] = (int) ($allocated[$lang] ?? 0);
            $remaining[$lang] = max(0, $row->{'count_' . $lang} - $alloc[$lang]);
        }
        $school = $row->school;

        return [
            'id' => $row->id,
            'school_id' => $row->school_id,
            'school' => $school ? [
                'id' => $school->id,
                'level' => $school->level,
                'display_name' => $school->display_name,
                'municipality_id' => $school->municipality_id,
                'languages' => $school->languages,
                'municipality' => $school->municipality?->only(['id', 'name', 'region_id', 'hub_municipality_id']),
            ] : null,
            'count_mk' => $row->count_mk,
            'count_sq' => $row->count_sq,
            'count_tr' => $row->count_tr,
            'count_total' => $row->count_total,
            'allocated' => $alloc + ['total' => array_sum($alloc)],
            'remaining' => $remaining + ['total' => array_sum($remaining)],
            'comment' => $row->comment,
            'source' => $row->source,
        ];
    }
}
