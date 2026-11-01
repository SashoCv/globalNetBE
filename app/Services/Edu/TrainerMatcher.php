<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSession;
use App\Models\EduMunicipality;
use App\Models\EduTrainer;
use Illuminate\Support\Collection;

/**
 * Обуки: which trainers can hold a group (plan §5.3).
 *
 * The group's centre is the centre of its venue municipality (or, before a
 * venue is set, the centre most of its participants come from). A trainer's
 * centres are the matrix rows for the program's topic, else the default
 * matrix (topic_id null), else — with no row that applies — the centre of
 * their home municipality plus the centres of its region.
 *
 * Problems are hard (the trainer is not eligible) or soft, as configured in
 * config('edu.trainer_rules'); "inactive" is always hard and
 * "language_unknown" / "no_venue" are always soft.
 */
class TrainerMatcher
{
    public const PROBLEMS = ['inactive', 'no_hub', 'not_in_topic', 'language_mismatch', 'language_unknown', 'conflict', 'no_venue'];

    public const PROBLEM_LABELS = [
        'inactive' => 'Обучувачот не е активен.',
        'no_hub' => 'Центарот на групата не е во матрицата на обучувачот (вон матрица).',
        'not_in_topic' => 'Обучувачот не е на листата за темата на програмата.',
        'language_mismatch' => 'Обучувачот не држи обуки на јазикот на групата.',
        'language_unknown' => 'Јазиците на обучувачот не се познати.',
        'conflict' => 'Обучувачот има друга група во истиот термин.',
        'no_venue' => 'Групата нема место на одржување, центарот не е проверен.',
    ];

    public const REASONS = ['hub_ok', 'hub_topic_matrix', 'hub_fallback_home', 'home_hub', 'home_region', 'topic_ok', 'language_ok', 'hub_from_schools'];

    /** Every trainer with what matching needs, by name. */
    public function trainers(?array $ids = null): Collection
    {
        return EduTrainer::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with([
                'hubs:id,trainer_id,hub_municipality_id,topic_id',
                'topics:id',
                'homeMunicipality:id,name,region_id,hub_municipality_id,is_training_hub',
            ])
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Scored candidates for a group, eligible ones first, then by score and
     * name.
     *
     * Options:
     *  - include_ineligible (bool, default false)
     *  - trainers (Collection from trainers(); loaded when missing)
     *  - ignore_group_ids (int[]): groups whose current trainer is not counted
     *    for load or conflicts (they are being reassigned in the same run)
     *  - bookings (trainer id => list of session rows) assigned in the same run
     *    but not saved
     *  - load (trainer id => int) groups assigned in the same run
     *
     * @return list<array{trainer: array, trainer_id: int, eligible: bool, score: int, matrix: string|null, reasons: list<string>, problems: list<string>, blocking: list<string>, conflicts: list<array>, load: int}>
     */
    public function candidates(EduGroup $group, array $options = []): array
    {
        $program = $group->program;
        $trainers = $options['trainers'] ?? $this->trainers();
        $ignore = array_map('intval', $options['ignore_group_ids'] ?? []);
        $venue = $group->venue_municipality_id ? EduMunicipality::find($group->venue_municipality_id) : null;
        [$hubId, $hubFromSchools] = $this->groupHub($group, $venue);
        $hubs = EduMunicipality::where('is_training_hub', true)->get(['id', 'region_id'])->keyBy('id');
        $regionId = $venue?->region_id ?? $hubs->get($hubId)?->region_id;

        $loads = EduGroup::query()
            ->where('program_id', $group->program_id)
            ->whereKeyNot($group->id)
            ->whereNotIn('id', $ignore ?: [0])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('trainer_id')
            ->selectRaw('trainer_id, count(*) as aggregate')
            ->groupBy('trainer_id')
            ->pluck('aggregate', 'trainer_id');
        $conflicts = $this->conflicts($group, $ignore, $options['bookings'] ?? []);

        $rules = config('edu.trainer_rules', []);
        $soft = [
            'no_hub' => ($rules['hub'] ?? 'strict') !== 'strict',
            'not_in_topic' => ($rules['topic'] ?? 'strict') !== 'strict',
            'language_mismatch' => ($rules['language'] ?? 'strict') !== 'strict',
            'conflict' => ($rules['conflict'] ?? 'block') !== 'block',
            'language_unknown' => true,
            'no_venue' => true,
            'inactive' => false,
        ];
        $scores = config('edu.trainer_scores', []);

        $out = [];
        foreach ($trainers as $trainer) {
            if (!$trainer->is_active && empty($options['include_ineligible'])) {
                continue;
            }
            $reasons = [];
            $problems = [];
            $score = 0;
            if (!$trainer->is_active) {
                $problems[] = 'inactive';
            }

            // Centre.
            [$eligibleHubs, $matrix] = $this->trainerHubs($trainer, $program->topic_id, $hubs, (bool) ($rules['fallback_region_hubs'] ?? true));
            if ($hubId === null) {
                $problems[] = 'no_venue';
            } elseif (in_array($hubId, $eligibleHubs, true)) {
                $reasons[] = 'hub_ok';
            } else {
                $problems[] = 'no_hub';
            }
            if ($matrix === 'topic') {
                $reasons[] = 'hub_topic_matrix';
            } elseif ($matrix === 'home') {
                $reasons[] = 'hub_fallback_home';
            }
            if ($hubFromSchools) {
                $reasons[] = 'hub_from_schools';
            }
            $home = $trainer->homeMunicipality;
            $homeHub = $home ? ($home->hub_municipality_id ?? ($home->is_training_hub ? $home->id : null)) : null;
            if ($hubId !== null && $homeHub === $hubId) {
                $reasons[] = 'home_hub';
                $score += $scores['home_hub'] ?? 3;
            } elseif ($home && $regionId !== null && $home->region_id === $regionId) {
                $reasons[] = 'home_region';
                $score += $scores['home_region'] ?? 2;
            }

            // Topic list (a topic matrix row counts as being on the list).
            if ($program->topic_id) {
                if ($trainer->topics->contains('id', $program->topic_id) || $trainer->hubs->contains('topic_id', $program->topic_id)) {
                    $reasons[] = 'topic_ok';
                } else {
                    $problems[] = 'not_in_topic';
                }
            }

            // Language.
            $languages = $trainer->languages ?? [];
            if ($languages === []) {
                $problems[] = 'language_unknown';
                $score += $scores['language_unknown'] ?? -2;
            } elseif (in_array($group->language, $languages, true)) {
                $reasons[] = 'language_ok';
            } else {
                $problems[] = 'language_mismatch';
            }

            // Time conflicts with the trainer's other groups.
            $trainerConflicts = $conflicts[$trainer->id] ?? [];
            if ($trainerConflicts) {
                $problems[] = 'conflict';
            }

            $load = (int) ($loads[$trainer->id] ?? 0) + (int) (($options['load'] ?? [])[$trainer->id] ?? 0);
            $score += $load * ($scores['per_group'] ?? -1);

            $blocking = [];
            foreach ($problems as $problem) {
                if (empty($soft[$problem])) {
                    $blocking[] = $problem;
                } elseif (!in_array($problem, ['language_unknown', 'no_venue'], true)) {
                    $score += $scores[$problem] ?? 0;
                }
            }

            $out[] = [
                'trainer' => [
                    'id' => $trainer->id,
                    'full_name' => $trainer->full_name,
                    'full_name_sq' => $trainer->full_name_sq,
                    'languages' => $languages,
                    'is_active' => $trainer->is_active,
                    'home_municipality' => $home?->only(['id', 'name', 'region_id']),
                ],
                'trainer_id' => $trainer->id,
                'eligible' => $blocking === [],
                'score' => $score,
                'matrix' => $matrix,
                'reasons' => $reasons,
                'problems' => $problems,
                'blocking' => $blocking,
                'conflicts' => $trainerConflicts,
                'load' => $load,
            ];
        }

        if (empty($options['include_ineligible'])) {
            $out = array_values(array_filter($out, fn ($c) => $c['eligible']));
        }

        return $this->sort($out);
    }

    /** The check for one trainer (eligible or not), or null when unknown. */
    public function check(EduGroup $group, int $trainerId, array $options = []): ?array
    {
        $trainers = $this->trainers([$trainerId]);
        if ($trainers->isEmpty()) {
            return null;
        }

        return $this->candidates($group, ['trainers' => $trainers, 'include_ineligible' => true] + $options)[0] ?? null;
    }

    /** Human-readable messages for problem codes. */
    public static function messages(array $problems): array
    {
        return array_values(array_map(fn ($p) => self::PROBLEM_LABELS[$p] ?? $p, $problems));
    }

    /**
     * The centre the group is held at: the venue's centre or, before a venue
     * is chosen, the centre most of its participants come from.
     *
     * @return array{0: int|null, 1: bool} [hub id, taken from the schools]
     */
    public function groupHub(EduGroup $group, ?EduMunicipality $venue = null): array
    {
        if ($venue) {
            return [$venue->hub_municipality_id ?? ($venue->is_training_hub ? $venue->id : null), false];
        }
        $group->loadMissing('allocations.school.municipality:id,hub_municipality_id');
        $weights = [];
        foreach ($group->allocations as $a) {
            $hub = $a->school?->municipality?->hub_municipality_id;
            if ($hub) {
                $weights[$hub] = ($weights[$hub] ?? 0) + max(1, $a->participant_count);
            }
        }
        if (!$weights) {
            return [null, false];
        }
        arsort($weights);

        return [(int) array_key_first($weights), true];
    }

    /**
     * @param  Collection<int, EduMunicipality>  $hubs  training centres by id
     * @return array{0: list<int>, 1: string|null} [centre ids, 'topic'|'default'|'home'|null]
     */
    private function trainerHubs(EduTrainer $trainer, ?int $topicId, Collection $hubs, bool $regionHubs): array
    {
        if ($topicId) {
            $rows = $trainer->hubs->where('topic_id', $topicId);
            if ($rows->isNotEmpty()) {
                return [$rows->pluck('hub_municipality_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'topic'];
            }
        }
        $rows = $trainer->hubs->whereNull('topic_id');
        if ($rows->isNotEmpty()) {
            return [$rows->pluck('hub_municipality_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'default'];
        }

        $home = $trainer->homeMunicipality;
        if (!$home) {
            return [[], null];
        }
        $ids = [];
        $homeHub = $home->hub_municipality_id ?? ($home->is_training_hub ? $home->id : null);
        if ($homeHub) {
            $ids[] = (int) $homeHub;
        }
        if ($regionHubs) {
            foreach ($hubs as $hub) {
                if ($hub->region_id === $home->region_id) {
                    $ids[] = (int) $hub->id;
                }
            }
        }

        return [array_values(array_unique($ids)), 'home'];
    }

    /**
     * Sessions of other non-cancelled groups that overlap the group's
     * sessions, by trainer. Only checked when the group has sessions.
     *
     * @return array<int, list<array>>
     */
    private function conflicts(EduGroup $group, array $ignore, array $bookings): array
    {
        $sessions = $group->sessions()->get();
        if ($sessions->isEmpty()) {
            return [];
        }
        $mine = $sessions->map(fn ($s) => ['date' => $s->date->format('Y-m-d'), 'start_time' => $s->start_time, 'end_time' => $s->end_time])->all();
        $dates = array_column($mine, 'date');

        $rows = EduGroupSession::query()
            ->join('edu_groups as g', 'g.id', '=', 'edu_group_sessions.group_id')
            ->join('edu_programs as p', 'p.id', '=', 'g.program_id')
            ->whereNotNull('g.trainer_id')
            ->where('g.status', '!=', 'cancelled')
            ->where('g.id', '!=', $group->id)
            ->whereNotIn('g.id', $ignore ?: [0])
            ->whereDate('edu_group_sessions.date', '>=', min($dates))
            ->whereDate('edu_group_sessions.date', '<=', max($dates))
            ->get([
                'g.trainer_id', 'g.id as group_id', 'g.program_id', 'p.title as program_title', 'g.number',
                'edu_group_sessions.date', 'edu_group_sessions.start_time', 'edu_group_sessions.end_time',
            ])
            ->map(fn ($r) => [
                'trainer_id' => (int) $r->trainer_id,
                'group_id' => (int) $r->group_id,
                'program_id' => (int) $r->program_id,
                'program_title' => $r->program_title,
                'number' => (int) $r->number,
                'date' => $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr((string) $r->date, 0, 10),
                'start_time' => $r->start_time,
                'end_time' => $r->end_time,
            ])
            ->all();
        foreach ($bookings as $trainerId => $list) {
            foreach ($list as $row) {
                if ((int) $row['group_id'] !== $group->id) {
                    $rows[] = ['trainer_id' => (int) $trainerId] + $row;
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            foreach ($mine as $s) {
                if ($row['date'] === $s['date'] && self::overlaps($s['start_time'], $s['end_time'], $row['start_time'], $row['end_time'])) {
                    $trainerId = $row['trainer_id'];
                    unset($row['trainer_id']);
                    $out[$trainerId][] = $row;
                    break;
                }
            }
        }

        return $out;
    }

    /** Two 'H:i' time ranges overlap; a missing time means the whole day. */
    public static function overlaps(?string $aStart, ?string $aEnd, ?string $bStart, ?string $bEnd): bool
    {
        $aStart = $aStart ?: '00:00';
        $bStart = $bStart ?: '00:00';
        $aEnd = $aEnd ?: '23:59';
        $bEnd = $bEnd ?: '23:59';

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    private function sort(array $candidates): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator('mk_MK') : null;
        usort($candidates, function ($a, $b) use ($collator) {
            return [$b['eligible'], $b['score']] <=> [$a['eligible'], $a['score']]
                ?: ($collator ? $collator->compare($a['trainer']['full_name'], $b['trainer']['full_name']) : strcmp($a['trainer']['full_name'], $b['trainer']['full_name']))
                ?: $a['trainer_id'] <=> $b['trainer_id'];
        });

        return $candidates;
    }
}
