<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Facades\DB;

/**
 * Обуки: the participant ledger of a program.
 *
 * - demand: edu_program_schools counts per school and language;
 * - allocated: the allocations (edu_group_schools) of the program's groups;
 * - remaining = demand − allocated, never negative by construction.
 *
 * In the "persons" mode the counts follow the participants: the per-school
 * counts are the participants per school and language, and an allocation is
 * the participants of that school and language in that group.
 */
class EduProgramCounts
{
    /** @return array<int, array<string, int>> school id => language => count */
    public function demand(EduProgram $program): array
    {
        $out = [];
        foreach (EduProgramSchool::where('program_id', $program->id)->get() as $row) {
            $out[$row->school_id] = $row->counts();
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $groupIds  only these groups (null = every group of the program)
     * @return array<int, array<string, int>> school id => language => count
     */
    public function allocated(EduProgram $program, ?array $groupIds = null): array
    {
        $rows = EduGroupSchool::query()
            ->join('edu_groups', 'edu_groups.id', '=', 'edu_group_schools.group_id')
            ->where('edu_groups.program_id', $program->id)
            ->when($groupIds !== null, fn ($q) => $q->whereIn('edu_groups.id', $groupIds))
            ->groupBy('edu_group_schools.school_id', 'edu_group_schools.language')
            ->selectRaw('edu_group_schools.school_id as school_id, edu_group_schools.language as language, SUM(participant_count) as n')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->school_id][$row->language] = (int) $row->n;
        }

        return $out;
    }

    public function remaining(EduProgram $program, int $schoolId, string $language): int
    {
        $demand = (int) EduProgramSchool::where('program_id', $program->id)->where('school_id', $schoolId)->value('count_' . $language);
        $allocated = (int) EduGroupSchool::query()
            ->whereIn('group_id', EduGroup::select('id')->where('program_id', $program->id))
            ->where('school_id', $schoolId)
            ->where('language', $language)
            ->sum('participant_count');

        return max(0, $demand - $allocated);
    }

    // ── persons mode ─────────────────────────────────────────────────────

    /**
     * Per-school counts from the participants (source = persons). Schools in
     * the program without participants stay with 0 (invited schools).
     *
     * @param  list<int>|null  $schoolIds  only these schools (null = all)
     */
    public function syncSchools(EduProgram $program, ?array $schoolIds = null): void
    {
        $counts = [];
        $rows = EduParticipant::query()
            ->where('program_id', $program->id)
            ->whereNotNull('school_id')
            ->when($schoolIds !== null, fn ($q) => $q->whereIn('school_id', $schoolIds))
            ->groupBy('school_id', 'language')
            ->selectRaw('school_id, language, COUNT(*) as n')
            ->get();
        foreach ($rows as $row) {
            $counts[(int) $row->school_id][$row->language] = (int) $row->n;
        }

        $existing = EduProgramSchool::where('program_id', $program->id)
            ->when($schoolIds !== null, fn ($q) => $q->whereIn('school_id', $schoolIds))
            ->get()->keyBy('school_id');

        foreach (array_unique(array_merge(array_keys($counts), $existing->keys()->all())) as $schoolId) {
            $values = ['source' => 'persons'];
            foreach (EduLanguage::CODES as $lang) {
                $values['count_' . $lang] = $counts[$schoolId][$lang] ?? 0;
            }
            $row = $existing->get($schoolId) ?? new EduProgramSchool(['program_id' => $program->id, 'school_id' => $schoolId]);
            $row->fill($values);
            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    /**
     * Allocations of the given groups from the participants in them. A row
     * that drops to 0 is removed unless its school has no participants at
     * all (then it is an invited school and stays).
     *
     * @param  list<int>|null  $groupIds  null = every group of the program
     */
    public function syncAllocations(EduProgram $program, ?array $groupIds = null): void
    {
        $groupIds ??= EduGroup::where('program_id', $program->id)->pluck('id')->all();
        $groupIds = array_values(array_unique(array_filter($groupIds)));
        if (!$groupIds) {
            return;
        }

        $counts = [];
        $rows = EduParticipant::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('school_id')
            ->groupBy('group_id', 'school_id', 'language')
            ->selectRaw('group_id, school_id, language, COUNT(*) as n')
            ->get();
        foreach ($rows as $row) {
            $counts[(int) $row->group_id][(int) $row->school_id][$row->language] = (int) $row->n;
        }
        $schoolsWithPeople = EduParticipant::where('program_id', $program->id)->whereNotNull('school_id')
            ->distinct()->pluck('school_id')->flip();

        foreach ($groupIds as $groupId) {
            $allocations = EduGroupSchool::where('group_id', $groupId)->get();
            $seen = [];
            foreach ($allocations as $allocation) {
                $n = $counts[$groupId][$allocation->school_id][$allocation->language] ?? 0;
                $seen[$allocation->school_id][$allocation->language] = true;
                if ($n === 0 && isset($schoolsWithPeople[$allocation->school_id])) {
                    $allocation->delete();
                } elseif ($allocation->participant_count !== $n) {
                    $allocation->update(['participant_count' => $n]);
                }
            }
            $sort = (int) $allocations->max('sort_order');
            foreach ($counts[$groupId] ?? [] as $schoolId => $byLanguage) {
                foreach ($byLanguage as $lang => $n) {
                    if (!isset($seen[$schoolId][$lang])) {
                        EduGroupSchool::create([
                            'group_id' => $groupId,
                            'school_id' => $schoolId,
                            'language' => $lang,
                            'participant_count' => $n,
                            'sort_order' => ++$sort,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Puts up to $n participants of a school and language from one group
     * (null = unassigned) into another group (null = unassign).
     *
     * @return list<int> ids of the moved participants
     */
    public function movePersons(EduProgram $program, int $schoolId, string $language, int $n, ?int $fromGroupId, ?int $toGroupId): array
    {
        if ($n <= 0) {
            return [];
        }
        $query = EduParticipant::query()
            ->where('program_id', $program->id)
            ->where('school_id', $schoolId)
            ->where('language', $language);
        $fromGroupId ? $query->where('group_id', $fromGroupId) : $query->whereNull('group_id');
        // Unassigning takes the latest additions first; assigning the earliest.
        $ids = $query->orderBy('id', $toGroupId === null ? 'desc' : 'asc')->limit($n)->pluck('id')->all();
        if ($ids) {
            EduParticipant::whereIn('id', $ids)->update(['group_id' => $toGroupId]);
        }

        return $ids;
    }

    /**
     * Switching a program from counts to persons: fill the existing
     * allocations with participants (up to each count), then recompute
     * every count from the participants.
     */
    public function adoptAllocations(EduProgram $program): void
    {
        DB::transaction(function () use ($program) {
            $allocations = EduGroupSchool::query()
                ->whereIn('group_id', EduGroup::select('id')->where('program_id', $program->id))
                ->orderBy('group_id')->orderBy('sort_order')->orderBy('id')
                ->get();
            foreach ($allocations as $allocation) {
                $already = EduParticipant::where('group_id', $allocation->group_id)
                    ->where('school_id', $allocation->school_id)->where('language', $allocation->language)->count();
                $this->movePersons($program, $allocation->school_id, $allocation->language, $allocation->participant_count - $already, null, $allocation->group_id);
            }
            $this->syncAll($program);
        });
    }

    /** Persons mode: every count and allocation from the participants. */
    public function syncAll(EduProgram $program): void
    {
        $this->syncSchools($program);
        $this->syncAllocations($program);
    }
}
