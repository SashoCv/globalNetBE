<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\EduProgramCounts;
use App\Services\Edu\ProgramZoneService;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: training programs (steps 1–4 and 8 of the client's checklist:
 * title, level, staff, group size, language map, default times).
 */
class EduProgramController extends Controller
{
    public function __construct(
        private ProgramZoneService $zones,
        private EduProgramCounts $counts,
        private EduGroupPresenter $presenter,
    ) {
    }

    // GET /api/edu-programs?status&search&school_level
    public function index(Request $request): JsonResponse
    {
        $query = EduProgram::query()
            ->with('topic:id,title,short_title')
            ->withCount('groups')
            ->withSum('schools as participants_total', 'count_total')
            ->withMin('groups as start_date', 'start_date')
            ->withMax('groups as end_date', 'end_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (in_array($request->query('status'), EduProgram::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }
        if (in_array($request->query('school_level'), EduProgram::LEVELS, true)) {
            $query->where('school_level', $request->query('school_level'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('title', 'like', '%' . $search . '%')->orWhere('period_label', 'like', '%' . $search . '%'));
        }

        return response()->json($query->get()->map(fn (EduProgram $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'topic_id' => $p->topic_id,
            'topic' => $p->topic?->only(['id', 'title', 'short_title']),
            'school_level' => $p->school_level,
            'staff_type' => $p->staff_type,
            'staff_label' => EduProgram::STAFF_LABELS[$p->staff_type] ?? $p->staff_type,
            'group_size_min' => $p->group_size_min,
            'group_size_max' => $p->group_size_max,
            'participant_mode' => $p->participant_mode,
            'status' => $p->status,
            'period_label' => $p->period_label,
            'groups_count' => (int) $p->groups_count,
            'participants_total' => (int) $p->participants_total,
            'start_date' => $p->start_date ? substr((string) $p->start_date, 0, 10) : null,
            'end_date' => $p->end_date ? substr((string) $p->end_date, 0, 10) : null,
            'created_at' => $p->created_at,
        ])->values());
    }

    // POST /api/edu-programs
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $program = DB::transaction(function () use ($data, $request) {
            $program = EduProgram::create($data + ['created_by' => $request->user()?->id]);
            $this->zones->initFromRegions($program);

            return $program;
        });

        return response()->json($this->payload($program->fresh()), 201);
    }

    // GET /api/edu-programs/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->payload(EduProgram::findOrFail($id)));
    }

    // PUT /api/edu-programs/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $this->validatePayload($request, $program);

        if (isset($data['school_level']) && $data['school_level'] !== $program->school_level
            && (EduProgramSchool::where('program_id', $program->id)->exists() || EduParticipant::where('program_id', $program->id)->exists())) {
            throw ValidationException::withMessages([
                'school_level' => 'Нивото не може да се смени: програмата веќе има училишта или учесници.',
            ]);
        }

        if (($data['participant_mode'] ?? null) === EduProgram::MODE_PERSONS && !$program->isPersons()
            && !EduParticipant::where('program_id', $program->id)->exists()
            && EduProgramSchool::where('program_id', $program->id)->where('count_total', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'participant_mode' => 'Програмата нема учесници: со префрлање во режим „Листа на учесници“ бројките по училиште би се изгубиле. Прво увезете ја листата.',
            ]);
        }

        DB::transaction(function () use ($program, $data) {
            $mode = $data['participant_mode'] ?? null;
            $oldMode = $program->participant_mode;
            $program->update($data);
            if ($mode && $mode !== $oldMode) {
                $this->switchMode($program->fresh(), $mode);
            }
        });

        return response()->json($this->payload($program->fresh()));
    }

    // DELETE /api/edu-programs/{id}
    public function destroy(int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        DB::transaction(fn () => $program->delete());

        return response()->json(null, 204);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * counts → persons: existing allocations are filled with participants and
     * every count is recomputed from them. persons → counts: the counts stay
     * as they are and become editable.
     */
    private function switchMode(EduProgram $program, string $mode): void
    {
        if ($mode === EduProgram::MODE_PERSONS) {
            $this->counts->adoptAllocations($program);
        } else {
            EduProgramSchool::where('program_id', $program->id)->where('source', 'persons')->update(['source' => 'manual']);
        }
    }

    private function validatePayload(Request $request, ?EduProgram $program = null): array
    {
        $req = $program ? 'sometimes|required' : 'required';
        $data = $request->validate([
            'title' => "{$req}|string|max:2000",
            'topic_id' => 'nullable|integer|exists:edu_topics,id',
            'school_level' => "{$req}|in:" . implode(',', EduProgram::LEVELS),
            'staff_type' => "{$req}|in:" . implode(',', EduProgram::STAFF_TYPES),
            'group_size_min' => "{$req}|integer|min:1|max:1000",
            'group_size_max' => "{$req}|integer|min:1|max:1000" . ($program ? '' : '|gte:group_size_min'),
            'trainer_assignment_mode' => 'sometimes|in:' . implode(',', EduProgram::ASSIGN_MODES),
            'participant_mode' => 'sometimes|in:' . implode(',', EduProgram::PARTICIPANT_MODES),
            'language_map' => 'sometimes|nullable|array',
            'language_map.*' => 'in:' . implode(',', EduLanguage::CODES),
            'period_label' => 'nullable|string|max:30',
            'default_start_time' => 'nullable|date_format:H:i',
            'default_end_time' => 'nullable|date_format:H:i' . ($program ? '' : '|after:default_start_time'),
            'default_days' => 'sometimes|integer|min:1|max:10',
            'status' => 'sometimes|in:' . implode(',', EduProgram::STATUSES),
        ]);

        if (array_key_exists('language_map', $data)) {
            if ($bad = array_diff(array_keys($data['language_map'] ?? []), EduLanguage::CODES)) {
                throw ValidationException::withMessages(['language_map' => 'Непознати јазици: ' . implode(', ', $bad) . '.']);
            }
            $data['language_map'] = EduProgram::normalizeLanguageMap(array_merge($program?->language_map ?? [], $data['language_map'] ?? []));
        }
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        // On update the pair rules look at the stored value of the other field.
        if ($program) {
            $min = $data['group_size_min'] ?? $program->group_size_min;
            $max = $data['group_size_max'] ?? $program->group_size_max;
            if ($max < $min) {
                throw ValidationException::withMessages(['group_size_max' => 'Максималната големина мора да е поголема или еднаква на минималната.']);
            }
            $start = array_key_exists('default_start_time', $data) ? $data['default_start_time'] : $program->default_start_time;
            $end = array_key_exists('default_end_time', $data) ? $data['default_end_time'] : $program->default_end_time;
            if ($start && $end && $end <= $start) {
                throw ValidationException::withMessages(['default_end_time' => 'Крајното време мора да е по почетното.']);
            }
        }

        return $data;
    }

    private function payload(EduProgram $program): array
    {
        $program->load('topic:id,title,short_title');

        return array_merge($program->toArray(), [
            'staff_label' => EduProgram::STAFF_LABELS[$program->staff_type] ?? $program->staff_type,
            'staff_plural' => EduProgram::STAFF_PLURAL[$program->staff_type] ?? $program->staff_type,
            'stats' => $this->stats($program),
        ]);
    }

    private function stats(EduProgram $program): array
    {
        $sums = EduProgramSchool::where('program_id', $program->id)
            ->selectRaw('COUNT(*) as schools, COALESCE(SUM(count_mk),0) as mk, COALESCE(SUM(count_sq),0) as sq, COALESCE(SUM(count_tr),0) as tr, COALESCE(SUM(count_total),0) as total')
            ->first();
        $groups = EduGroup::where('program_id', $program->id)->get();
        $this->presenter->load($groups);
        $assigned = (int) $groups->sum(fn ($g) => $g->allocations->sum('participant_count'));
        $total = (int) $sums->total;
        $active = $groups->where('status', '!=', 'cancelled');

        return [
            'schools' => (int) $sums->schools,
            'participants_total' => $total,
            'by_language' => ['mk' => (int) $sums->mk, 'sq' => (int) $sums->sq, 'tr' => (int) $sums->tr],
            'persons' => EduParticipant::where('program_id', $program->id)->count(),
            'assigned' => $assigned,
            'unassigned' => max(0, $total - $assigned),
            'groups' => $groups->count(),
            'groups_without_trainer' => $active->whereNull('trainer_id')->count(),
            'groups_without_dates' => $active->whereNull('start_date')->count(),
            'warnings_count' => $groups->filter(fn ($g) => $this->presenter->warnings($g, $program))->count(),
        ];
    }
}
