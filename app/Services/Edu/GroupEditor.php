<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: manual regrouping (plan §5.2). Every operation keeps the ledger
 * consistent: no school is allocated more participants than it has, and in
 * the "persons" mode the participants move with the counts (an allocation
 * is then always the participants of that school and language in the
 * group).
 */
class GroupEditor
{
    public function __construct(private EduProgramCounts $counts)
    {
    }

    /** A new, empty group numbered after the last one. */
    public function create(EduProgram $program, string $language, ?int $zoneId = null, array $extra = []): EduGroup
    {
        return EduGroup::create($extra + [
            'program_id' => $program->id,
            'number' => $this->nextNumber($program),
            'zone_id' => $zoneId,
            'language' => $language,
            'status' => 'draft',
        ]);
    }

    /** Deletes a group; its participants and counts go back to unassigned. */
    public function delete(EduGroup $group): void
    {
        DB::transaction(function () use ($group) {
            EduParticipant::where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();
        });
    }

    /**
     * Puts $count participants of a school (in their language) into $target,
     * taken from $from or, without it, from the unassigned ones. 0 with no
     * source places an invited school.
     *
     * @return array{0: EduGroup, 1: EduGroup|null}
     */
    public function allocate(EduGroup $target, int $schoolId, string $language, int $count, ?EduGroup $from = null): array
    {
        $program = $target->program;
        if ($from && $from->program_id !== $target->program_id) {
            throw ValidationException::withMessages(['from_group_id' => 'Групата е од друга програма.']);
        }
        if ($from && $from->id === $target->id) {
            throw ValidationException::withMessages(['from_group_id' => 'Изворната и целната група се исти.']);
        }
        if (!EduProgramSchool::where('program_id', $program->id)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['school_id' => 'Училиштето не е во програмата.']);
        }

        DB::transaction(function () use ($program, $target, $schoolId, $language, $count, $from) {
            if ($from) {
                $source = EduGroupSchool::where('group_id', $from->id)->where('school_id', $schoolId)->where('language', $language)->first();
                if (!$source) {
                    throw ValidationException::withMessages(['school_id' => 'Училиштето го нема во изворната група.']);
                }
                if ($count === 0 && $source->participant_count > 0) {
                    throw ValidationException::withMessages(['count' => 'Внесете број поголем од 0.']);
                }
                if ($count > $source->participant_count) {
                    throw ValidationException::withMessages(['count' => "Во изворната група има само {$source->participant_count} учесници од ова училиште."]);
                }
                $moveRow = $count === $source->participant_count;
                $this->addTo($target, $schoolId, $language, $count);
                $moveRow ? $source->delete() : $source->decrement('participant_count', $count);
                if ($program->isPersons()) {
                    $this->counts->movePersons($program, $schoolId, $language, $count, $from->id, $target->id);
                    $this->counts->syncAllocations($program, [$target->id, $from->id]);
                }

                return;
            }

            $remaining = $this->counts->remaining($program, $schoolId, $language);
            if ($count > $remaining) {
                throw ValidationException::withMessages(['count' => "Нераспоредени се само {$remaining} учесници од ова училиште на овој јазик."]);
            }
            $this->addTo($target, $schoolId, $language, $count);
            if ($program->isPersons()) {
                $this->counts->movePersons($program, $schoolId, $language, $count, null, $target->id);
                $this->counts->syncAllocations($program, [$target->id]);
            }
        });

        return [$target->fresh(), $from?->fresh()];
    }

    /** Sets an allocation's count; more comes from the unassigned ones. */
    public function setCount(EduGroupSchool $allocation, int $count): ?EduGroupSchool
    {
        $group = $allocation->group;
        $program = $group->program;
        $delta = $count - $allocation->participant_count;
        if ($delta > 0) {
            $remaining = $this->counts->remaining($program, $allocation->school_id, $allocation->language);
            if ($delta > $remaining) {
                $max = $allocation->participant_count + $remaining;
                throw ValidationException::withMessages(['participant_count' => "Најмногу {$max}: нераспоредени се само {$remaining} учесници од ова училиште на овој јазик."]);
            }
        }

        DB::transaction(function () use ($allocation, $count, $delta, $program, $group) {
            $allocation->update(['participant_count' => $count]);
            if ($program->isPersons() && $delta !== 0) {
                $delta > 0
                    ? $this->counts->movePersons($program, $allocation->school_id, $allocation->language, $delta, null, $group->id)
                    : $this->counts->movePersons($program, $allocation->school_id, $allocation->language, -$delta, $group->id, null);
                $this->counts->syncAllocations($program, [$group->id]);
            }
        });

        return EduGroupSchool::find($allocation->id);
    }

    /** Removes an allocation; its participants become unassigned. */
    public function removeAllocation(EduGroupSchool $allocation): void
    {
        $group = $allocation->group;
        $program = $group->program;
        DB::transaction(function () use ($allocation, $group, $program) {
            if ($program->isPersons()) {
                EduParticipant::where('group_id', $group->id)->where('school_id', $allocation->school_id)
                    ->where('language', $allocation->language)->update(['group_id' => null]);
            }
            $allocation->delete();
        });
    }

    /**
     * Merges groups into $target: counts add up per (school, language).
     *
     * @param  Collection<int, EduGroup>  $others
     * @return array{0: EduGroup, 1: list<array{code: string, message: string}>}
     */
    public function merge(EduGroup $target, Collection $others): array
    {
        $program = $target->program;
        $warnings = [];
        foreach ($others as $other) {
            if ($other->program_id !== $target->program_id) {
                throw ValidationException::withMessages(['group_ids' => 'Сите групи мора да се од истата програма.']);
            }
            if ($other->id === $target->id) {
                throw ValidationException::withMessages(['group_ids' => 'Групата не може да се спои сама со себе.']);
            }
            if ($other->language !== $target->language) {
                $warnings[] = [
                    'code' => 'language_differs',
                    'message' => "Група {$other->number} е на " . (EduLanguage::LABELS[$other->language] ?? $other->language)
                        . ', а Група ' . $target->number . ' на ' . (EduLanguage::LABELS[$target->language] ?? $target->language) . '.',
                ];
            }
        }

        DB::transaction(function () use ($target, $others, $program) {
            foreach ($others as $other) {
                foreach (EduGroupSchool::where('group_id', $other->id)->orderBy('sort_order')->orderBy('id')->get() as $allocation) {
                    $this->addTo($target, $allocation->school_id, $allocation->language, $allocation->participant_count);
                }
                EduParticipant::where('group_id', $other->id)->update(['group_id' => $target->id]);
                $other->delete();
            }
            if ($program->isPersons()) {
                $this->counts->syncAllocations($program, [$target->id]);
            }
        });

        return [$target->fresh(), $warnings];
    }

    /**
     * Moves parts of a group's allocations into a new group (same zone and
     * language).
     *
     * @param  list<array{allocation_id: int, count: int}>  $parts
     * @return array{0: EduGroup, 1: EduGroup}
     */
    public function split(EduGroup $group, array $parts): array
    {
        $allocations = EduGroupSchool::where('group_id', $group->id)->whereIn('id', array_column($parts, 'allocation_id'))->get()->keyBy('id');
        foreach ($parts as $i => $part) {
            $allocation = $allocations->get((int) $part['allocation_id']);
            if (!$allocation) {
                throw ValidationException::withMessages(["allocations.{$i}.allocation_id" => 'Распределбата не е во оваа група.']);
            }
            if ((int) $part['count'] > $allocation->participant_count) {
                throw ValidationException::withMessages(["allocations.{$i}.count" => "Најмногу {$allocation->participant_count}."]);
            }
        }

        $new = DB::transaction(function () use ($group, $parts, $allocations) {
            $new = $this->create($group->program, $group->language, $group->zone_id);
            foreach ($parts as $part) {
                $allocation = $allocations->get((int) $part['allocation_id'])->fresh();
                if ((int) $part['count'] === 0 && $allocation->participant_count > 0) {
                    continue;
                }
                $this->allocate($new, $allocation->school_id, $allocation->language, (int) $part['count'], $group);
            }

            return $new;
        });

        return [$group->fresh(), $new->fresh()];
    }

    /**
     * Numbers the groups 1..N: first the ones in $order, then the rest by
     * zone order, language and current number.
     *
     * @param  list<int>|null  $order
     * @return Collection<int, EduGroup>
     */
    public function renumber(EduProgram $program, ?array $order = null): Collection
    {
        $groups = EduGroup::where('program_id', $program->id)->get()->keyBy('id');
        $order = array_values(array_unique(array_map('intval', $order ?? [])));
        if ($bad = array_diff($order, $groups->keys()->all())) {
            throw ValidationException::withMessages(['order' => 'Групите не се од оваа програма: ' . implode(', ', $bad) . '.']);
        }
        $zoneOrder = EduProgramZone::where('program_id', $program->id)->pluck('sort_order', 'id');
        $rest = $groups->reject(fn (EduGroup $g) => in_array($g->id, $order, true))->sortBy(fn (EduGroup $g) => [
            $g->zone_id ? ($zoneOrder[$g->zone_id] ?? 9999) : 9999,
            $g->zone_id ?? PHP_INT_MAX,
            array_search($g->language, EduLanguage::CODES, true),
            $g->number,
        ])->pluck('id')->all();
        $sequence = array_merge($order, $rest);

        DB::transaction(function () use ($program, $sequence) {
            // Out of the way first so the unique (program, number) never clashes.
            EduGroup::where('program_id', $program->id)->update(['number' => DB::raw('number + 30000')]);
            foreach ($sequence as $i => $id) {
                EduGroup::whereKey($id)->update(['number' => $i + 1]);
            }
        });

        return EduGroup::where('program_id', $program->id)->orderBy('number')->get();
    }

    private function addTo(EduGroup $group, int $schoolId, string $language, int $count): void
    {
        $row = EduGroupSchool::where('group_id', $group->id)->where('school_id', $schoolId)->where('language', $language)->first();
        if ($row) {
            if ($count > 0) {
                $row->increment('participant_count', $count);
            }

            return;
        }
        EduGroupSchool::create([
            'group_id' => $group->id,
            'school_id' => $schoolId,
            'language' => $language,
            'participant_count' => $count,
            'sort_order' => (int) EduGroupSchool::where('group_id', $group->id)->max('sort_order') + 1,
        ]);
    }

    private function nextNumber(EduProgram $program): int
    {
        return (int) EduGroup::where('program_id', $program->id)->max('number') + 1;
    }
}
