<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSession;
use App\Models\EduLocation;
use App\Models\EduSchool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Обуки: a group's place and days (plan §5.4) — sessions, the denormalised
 * start/end dates, the draft → planned status and the trainer re-check after
 * a change.
 */
class GroupScheduler
{
    public function __construct(private TrainerMatcher $matcher)
    {
    }

    /** The school's location (kind school), created from the school when missing. */
    public function locationForSchool(EduSchool $school): EduLocation
    {
        $location = EduLocation::where('school_id', $school->id)->where('kind', 'school')->orderBy('id')->first();
        if ($location) {
            return $location;
        }

        return EduLocation::create([
            'name' => mb_substr($school->display_name, 0, 255),
            'kind' => 'school',
            'school_id' => $school->id,
            'municipality_id' => $school->municipality_id,
            'address' => $school->address,
            'contact' => implode(', ', $school->phones ?? []) ?: null,
        ]);
    }

    /**
     * $days training days from $start: consecutive working days when
     * weekends are skipped (a start on a weekend moves to Monday).
     *
     * @return list<string> Y-m-d
     */
    public function workingDays(string $start, int $days): array
    {
        $skip = (bool) config('edu.skip_weekends', true);
        $date = CarbonImmutable::parse($start)->startOfDay();
        $out = [];
        while (count($out) < $days) {
            if (!$skip || !$date->isWeekend()) {
                $out[] = $date->format('Y-m-d');
            }
            $date = $date->addDay();
        }

        return $out;
    }

    /** @return array{0: string, 1: string} default start and end time of the program's sessions */
    public function defaultTimes(EduGroup $group): array
    {
        $fallback = config('edu.default_session_times', ['08:30', '13:30']);
        $program = $group->program;

        return [$program->default_start_time ?: $fallback[0], $program->default_end_time ?: $fallback[1]];
    }

    /**
     * Replaces the group's sessions.
     *
     * @param  list<array{date: string, start_time?: string|null, end_time?: string|null}>  $sessions
     */
    public function replaceSessions(EduGroup $group, array $sessions, ?string $startTime = null, ?string $endTime = null): void
    {
        [$defStart, $defEnd] = $this->defaultTimes($group);
        usort($sessions, fn ($a, $b) => strcmp($a['date'], $b['date']));
        DB::transaction(function () use ($group, $sessions, $startTime, $endTime, $defStart, $defEnd) {
            EduGroupSession::where('group_id', $group->id)->delete();
            foreach (array_values($sessions) as $i => $s) {
                EduGroupSession::create([
                    'group_id' => $group->id,
                    'date' => $s['date'],
                    'start_time' => ($s['start_time'] ?? null) ?: ($startTime ?: $defStart),
                    'end_time' => ($s['end_time'] ?? null) ?: ($endTime ?: $defEnd),
                    'sort_order' => $i + 1,
                ]);
            }
            $this->syncDates($group);
        });
    }

    /** Sets new times on every session of the group. */
    public function setTimes(EduGroup $group, ?string $startTime, ?string $endTime): void
    {
        $update = array_filter(['start_time' => $startTime, 'end_time' => $endTime]);
        if ($update) {
            EduGroupSession::where('group_id', $group->id)->update($update);
        }
    }

    /**
     * Shifts every session by $days calendar days, keeping the gaps between
     * them. Updated in an order that never hits unique(group_id, date).
     */
    public function move(EduGroup $group, int $days): void
    {
        if ($days === 0) {
            return;
        }
        DB::transaction(function () use ($group, $days) {
            $sessions = EduGroupSession::where('group_id', $group->id)->orderBy('date', $days > 0 ? 'desc' : 'asc')->get();
            foreach ($sessions as $session) {
                $session->update(['date' => CarbonImmutable::parse($session->date)->addDays($days)->format('Y-m-d')]);
            }
            $this->syncDates($group);
        });
    }

    /** start_date / end_date from the sessions; then the status. */
    public function syncDates(EduGroup $group): void
    {
        $dates = EduGroupSession::where('group_id', $group->id)->orderBy('date')->get(['date'])->pluck('date');
        $group->start_date = $dates->first()?->format('Y-m-d');
        $group->end_date = $dates->last()?->format('Y-m-d');
        $group->save();
        $this->refreshStatus($group);
    }

    /**
     * A draft with a trainer, a place and dates becomes planned; a planned
     * group that loses one of them goes back to draft. Held and cancelled
     * groups are left alone.
     */
    public function refreshStatus(EduGroup $group): void
    {
        $complete = $group->trainer_id && ($group->location_id || filled($group->location_text)) && $group->start_date;
        if ($group->status === 'draft' && $complete) {
            $group->update(['status' => 'planned']);
        } elseif ($group->status === 'planned' && !$complete) {
            $group->update(['status' => 'draft']);
        }
    }

    /**
     * Warnings after a change of place or dates: the assigned trainer is
     * checked again (and kept), sessions on a weekend are pointed out.
     *
     * @return list<array{code: string, message: string}>
     */
    public function warnings(EduGroup $group): array
    {
        $out = [];
        foreach (EduGroupSession::where('group_id', $group->id)->orderBy('date')->get() as $session) {
            if ($session->date->isWeekend()) {
                $out[] = ['code' => 'weekend', 'message' => 'Сесијата на ' . $session->date->format('d.m.Y') . ' е за викенд.'];
            }
        }
        if ($group->trainer_id) {
            $check = $this->matcher->check($group, $group->trainer_id);
            foreach ($check['problems'] ?? [] as $problem) {
                if ($problem === 'no_venue') {
                    continue;
                }
                $message = TrainerMatcher::PROBLEM_LABELS[$problem] ?? $problem;
                if ($problem === 'conflict') {
                    $message .= ' ' . collect($check['conflicts'])->map(fn ($c) => 'Група ' . $c['number'] . ' (' . $c['date'] . ' ' . $c['start_time'] . '-' . $c['end_time'] . ', ' . mb_strimwidth((string) $c['program_title'], 0, 60, '…') . ')')->unique()->implode('; ');
                }
                $out[] = ['code' => 'trainer_' . $problem, 'message' => $message];
            }
        }

        return $out;
    }
}
