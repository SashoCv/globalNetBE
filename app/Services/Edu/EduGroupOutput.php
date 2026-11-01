<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduMunicipality;
use App\Models\EduProgram;
use App\Models\EduSchool;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: how a group is printed in the outputs (the "Динамика" Excel and the
 * group documents) — which groups, the place, the city, the trainer's name
 * and the schools with their counts ("ООУ „X“-Велес (1+1т)").
 */
class EduGroupOutput
{
    public const RELATIONS = [
        'allocations.school.municipality',
        'location.school.municipality.region',
        'location.municipality.region',
        'venueMunicipality.region',
        'trainer',
        'sessions',
    ];

    /**
     * The program's groups for an output, by number. Explicit group_ids win
     * (whatever their status); otherwise cancelled groups are left out
     * unless statuses names them.
     *
     * @param  list<int>|null  $groupIds
     * @param  list<string>|null  $statuses
     * @return Collection<int, EduGroup>
     */
    public function groups(EduProgram $program, ?array $groupIds = null, ?array $statuses = null): Collection
    {
        $query = EduGroup::where('program_id', $program->id)->orderBy('number')->orderBy('id');
        if ($groupIds) {
            $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
            $query->whereIn('id', $groupIds);
        }
        if ($statuses) {
            $query->whereIn('status', $statuses);
        } elseif (!$groupIds) {
            $query->where('status', '!=', 'cancelled');
        }
        $groups = $query->with(self::RELATIONS)->get();

        if ($groupIds && !$statuses && $groups->count() !== count($groupIds)) {
            throw ValidationException::withMessages(['group_ids' => 'Некои од избраните групи не припаѓаат на програмата.']);
        }

        return $groups;
    }

    public function load(EduGroup $group): EduGroup
    {
        return $group->loadMissing(self::RELATIONS);
    }

    /** The host school, when the group is held at a school. */
    public function hostSchool(EduGroup $group): ?EduSchool
    {
        $location = $group->location;

        return $location && $location->kind === 'school' ? $location->school : null;
    }

    /** "Место каде се реализира обуката": the host school's display_name, the location's name or the free text. */
    public function place(EduGroup $group): ?string
    {
        return $this->hostSchool($group)?->display_name
            ?? $group->location?->name
            ?? (filled($group->location_text) ? $group->location_text : null);
    }

    public function address(EduGroup $group): ?string
    {
        $school = $this->hostSchool($group);
        if ($school && filled($school->address)) {
            return filled($school->address_sq) ? "{$school->address} / {$school->address_sq}" : $school->address;
        }

        return filled($group->location?->address) ? $group->location->address : null;
    }

    /** Municipality the group is held in: the schedule's one, else the location's. */
    public function venueMunicipality(EduGroup $group): ?EduMunicipality
    {
        return $group->venueMunicipality
            ?? $group->location?->municipality
            ?? $this->hostSchool($group)?->municipality;
    }

    /** "Град": the venue municipality, "Скопје" for any part of Skopje. */
    public function city(EduGroup $group): ?string
    {
        $m = $this->venueMunicipality($group);
        if (!$m) {
            return null;
        }

        return $m->part_of_skopje ? 'Скопје' : $m->name;
    }

    /** Albanian groups print the trainer's Latin name when there is one. */
    public function trainerName(EduGroup $group): ?string
    {
        $trainer = $group->trainer;
        if (!$trainer) {
            return null;
        }

        return $group->language === 'sq' && filled($trainer->full_name_sq) ? $trainer->full_name_sq : $trainer->full_name;
    }

    public function languageLabel(?string $code): string
    {
        return EduLanguage::LABELS[$code] ?? (string) $code;
    }

    public function languageName(?string $code): string
    {
        return EduLanguage::NAMES[$code] ?? (string) $code;
    }

    public function total(EduGroup $group): int
    {
        return (int) $group->allocations->sum('participant_count');
    }

    /** @return array{dates: string, time: string, days: int} */
    public function schedule(EduGroup $group): array
    {
        return DateRangeFormatter::sessions($group->sessions->map(fn ($s) => [
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
        ]));
    }

    /**
     * One entry per school, in allocation order; the languages of a school
     * are joined into one count ("1+1т").
     *
     * @return list<array{school_id: int, school: string, municipality: ?string, count: int, count_label: string}>
     */
    public function schools(EduGroup $group): array
    {
        $bySchool = [];
        foreach ($group->allocations as $a) {
            $entry = $bySchool[$a->school_id] ?? [
                'school_id' => $a->school_id,
                'school' => (string) ($a->school?->display_name ?? ''),
                'municipality' => $a->school?->municipality?->name,
                'counts' => [],
            ];
            $entry['counts'][$a->language] = ($entry['counts'][$a->language] ?? 0) + (int) $a->participant_count;
            $bySchool[$a->school_id] = $entry;
        }

        return array_values(array_map(fn ($e) => [
            'school_id' => $e['school_id'],
            'school' => $e['school'],
            'municipality' => $e['municipality'],
            'count' => array_sum($e['counts']),
            'count_label' => $this->countLabel($e['counts'], (string) $group->language),
        ], $bySchool));
    }

    /**
     * Counts per participant language → "3", "1+1т", "2+1а". The group's own
     * language comes first without a suffix, the others carry their
     * EduLanguage::SHORT letter ("м" for Macedonian outside a Macedonian
     * group). Zero counts are dropped unless all are zero ("0", an invited
     * school).
     *
     * @param  array<string, int>  $counts
     */
    public function countLabel(array $counts, string $groupLanguage): string
    {
        $order = array_values(array_unique(array_merge([$groupLanguage], EduLanguage::CODES, array_keys($counts))));
        $parts = [];
        foreach ($order as $lang) {
            $n = (int) ($counts[$lang] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $suffix = $lang === $groupLanguage ? '' : ((EduLanguage::SHORT[$lang] ?? '') ?: ($lang === 'mk' ? 'м' : $lang));
            $parts[] = $n . $suffix;
        }

        return $parts === [] ? '0' : implode(config('edu.dynamics_separators.language_count', '+'), $parts);
    }

    /** "ООУ „X“-Велес (3), ООУ „Y“-Чашка (1+1т)". */
    public function schoolsText(EduGroup $group): string
    {
        $sep = config('edu.dynamics_separators', []);
        $municipalitySep = $sep['municipality'] ?? '-';

        return implode($sep['school'] ?? ', ', array_map(
            fn ($s) => $s['school'] . (filled($s['municipality']) ? $municipalitySep . $s['municipality'] : '') . " ({$s['count_label']})",
            $this->schools($group),
        ));
    }

    /** ASCII slug of the program title for file names (max ~60 chars), or "programa-{id}". */
    public function slug(EduProgram $program): string
    {
        $slug = Str::slug((string) $program->title);
        if (strlen($slug) > 60) {
            $cut = substr($slug, 0, 61);
            $slug = str_contains($cut, '-') ? substr($cut, 0, strrpos($cut, '-')) : substr($slug, 0, 60);
        }

        return $slug !== '' ? $slug : 'programa-' . $program->id;
    }
}
