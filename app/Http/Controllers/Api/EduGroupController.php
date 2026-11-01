<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Services\Edu\AutoGrouper;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\EduProgramCounts;
use App\Services\Edu\GroupEditor;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: the groups of a program (step 6) — automatic grouping, the group
 * board, merge, split and renumber.
 */
class EduGroupController extends Controller
{
    public function __construct(
        private AutoGrouper $grouper,
        private GroupEditor $editor,
        private EduGroupPresenter $presenter,
        private EduProgramCounts $counts,
    ) {
    }

    // POST /api/edu-programs/{id}/groups/auto  body: {dry_run=true, keep_locked=true, split_schools=true, merge_undersized=false, max_overflow?}
    public function auto(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'dry_run' => 'sometimes|boolean',
            'keep_locked' => 'sometimes|boolean',
            'split_schools' => 'sometimes|boolean',
            'merge_undersized' => 'sometimes|boolean',
            'max_overflow' => 'nullable|integer|min:0|max:1000',
        ]);

        return response()->json($this->grouper->run($program, [
            'dry_run' => $request->boolean('dry_run', true),
            'keep_locked' => $request->boolean('keep_locked', true),
            'split_schools' => $request->boolean('split_schools', true),
            'merge_undersized' => $request->boolean('merge_undersized', false),
            'max_overflow' => $data['max_overflow'] ?? null,
        ]));
    }

    // GET /api/edu-programs/{id}/groups?zone_id&language&trainer_id
    public function index(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $query = EduGroup::where('program_id', $program->id)->orderBy('number');
        if ($zoneId = $request->integer('zone_id')) {
            $query->where('zone_id', $zoneId);
        }
        if (EduLanguage::isValid($request->query('language'))) {
            $query->where('language', $request->query('language'));
        }
        if ($trainerId = $request->integer('trainer_id')) {
            $query->where('trainer_id', $trainerId);
        }
        $groups = $query->get();
        $this->presenter->load($groups);

        return response()->json($groups->map(fn ($g) => $this->presenter->payload($g, $program))->values());
    }

    // GET /api/edu-groups/{id}
    public function show(int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);

        return response()->json($this->presenter->payload($group, $group->program));
    }

    // GET /api/edu-programs/{id}/unassigned
    public function unassigned(int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $allocated = $this->counts->allocated($program);
        $schools = EduProgramSchool::where('program_id', $program->id)
            ->with(['school:id,display_name,municipality_id,languages', 'school.municipality:id,name,region_id,hub_municipality_id'])
            ->get()
            ->map(function (EduProgramSchool $row) use ($allocated) {
                $remaining = [];
                foreach (EduLanguage::CODES as $lang) {
                    $remaining[$lang] = max(0, $row->{'count_' . $lang} - ($allocated[$row->school_id][$lang] ?? 0));
                }

                return [
                    'school_id' => $row->school_id,
                    'display_name' => $row->school?->display_name,
                    'municipality_id' => $row->school?->municipality_id,
                    'municipality' => $row->school?->municipality?->only(['id', 'name', 'region_id', 'hub_municipality_id']),
                    'languages' => $row->school?->languages ?? [],
                    'remaining' => $remaining,
                    'total' => array_sum($remaining),
                    'invited' => $row->count_total === 0 && !isset($allocated[$row->school_id]),
                ];
            })
            ->filter(fn ($s) => $s['total'] > 0 || $s['invited'])
            ->sortBy(fn ($s) => [$s['municipality']['name'] ?? '', $s['display_name']])
            ->values();

        $participants = $program->isPersons()
            ? EduParticipant::where('program_id', $program->id)->whereNull('group_id')->orderBy('full_name')->orderBy('id')
                ->get(['id', 'full_name', 'school_id', 'municipality_id', 'language', 'email', 'phone', 'position'])
            : collect();

        return response()->json(['schools' => $schools, 'participants' => $participants]);
    }

    // POST /api/edu-programs/{id}/groups  body: {language, zone_id?, comment?}
    public function store(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'language' => 'required|in:' . implode(',', EduLanguage::CODES),
            'zone_id' => 'nullable|integer',
            'comment' => 'nullable|string|max:5000',
        ]);
        $this->checkZone($program, $data['zone_id'] ?? null);
        $group = $this->editor->create($program, $data['language'], $data['zone_id'] ?? null, isset($data['comment']) ? ['comment' => $data['comment']] : []);

        return response()->json($this->presenter->payload($group->fresh(), $program), 201);
    }

    // PUT /api/edu-groups/{id}  body: {language?, zone_id?, status?, is_locked?, comment?}
    public function update(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'language' => 'sometimes|required|in:' . implode(',', EduLanguage::CODES),
            'zone_id' => 'sometimes|nullable|integer',
            'status' => 'sometimes|required|in:' . implode(',', EduGroup::STATUSES),
            'is_locked' => 'sometimes|boolean',
            'comment' => 'sometimes|nullable|string|max:5000',
        ]);
        if (array_key_exists('zone_id', $data)) {
            $this->checkZone($group->program, $data['zone_id']);
        }
        $group->update($data);

        return response()->json($this->presenter->payload($group->fresh(), $group->program));
    }

    // DELETE /api/edu-groups/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->editor->delete(EduGroup::findOrFail($id));

        return response()->json(null, 204);
    }

    // POST /api/edu-groups/{id}/merge  body: {group_ids: [..]}
    public function merge(Request $request, int $id): JsonResponse
    {
        $target = EduGroup::findOrFail($id);
        $data = $request->validate([
            'group_ids' => 'required|array|min:1|max:200',
            'group_ids.*' => 'integer|distinct',
        ]);
        $others = EduGroup::whereIn('id', $data['group_ids'])->orderBy('number')->get();
        if ($others->count() !== count($data['group_ids'])) {
            throw ValidationException::withMessages(['group_ids' => 'Некои групи не постојат.']);
        }
        [$group, $warnings] = $this->editor->merge($target, $others);

        return response()->json($this->presenter->payload($group, $group->program) + ['merge_warnings' => $warnings]);
    }

    // POST /api/edu-groups/{id}/split  body: {allocations: [{allocation_id, count}]}
    public function split(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'allocations' => 'required|array|min:1|max:500',
            'allocations.*.allocation_id' => 'required|integer|distinct',
            'allocations.*.count' => 'required|integer|min:0',
        ]);
        [$group, $new] = $this->editor->split($group, $data['allocations']);
        $program = $group->program;

        return response()->json([
            'group' => $this->presenter->payload($group, $program),
            'new_group' => $this->presenter->payload($new, $program),
        ]);
    }

    // POST /api/edu-programs/{id}/groups/renumber  body: {order?: [group ids]}
    public function renumber(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $request->validate([
            'order' => 'nullable|array|max:1000',
            'order.*' => 'integer',
        ]);
        $groups = $this->editor->renumber($program, $data['order'] ?? null);
        $this->presenter->load($groups);

        return response()->json($groups->map(fn ($g) => $this->presenter->payload($g, $program))->values());
    }

    private function checkZone(EduProgram $program, ?int $zoneId): void
    {
        if ($zoneId && !EduProgramZone::where('program_id', $program->id)->whereKey($zoneId)->exists()) {
            throw ValidationException::withMessages(['zone_id' => 'Зоната не е од оваа програма.']);
        }
    }
}
