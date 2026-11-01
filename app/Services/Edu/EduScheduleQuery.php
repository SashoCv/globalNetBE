<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Обуки: groups across programs for the calendar, the reports and a
 * trainer's page — the compact "schedule item" shape.
 */
class EduScheduleQuery
{
    public const RELATIONS = [
        'program:id,title,topic_id,period_label,status',
        'location:id,name,kind,school_id,municipality_id,address',
        'venueMunicipality:id,name,region_id,hub_municipality_id',
        'trainer:id,full_name,full_name_sq,languages',
        'sessions:id,group_id,date,start_time,end_time,sort_order',
    ];

    /** Groups with at least one session in [from, to] (Y-m-d). */
    public function inRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if (!$from && !$to) {
            return $query;
        }

        return $query->whereHas('sessions', function ($q) use ($from, $to) {
            if ($from) {
                $q->whereDate('date', '>=', $from);
            }
            if ($to) {
                $q->whereDate('date', '<=', $to);
            }
        });
    }

    /** @param Collection<int, EduGroup> $groups */
    public function items(Collection $groups): Collection
    {
        $groups->load(self::RELATIONS);
        $groups->loadSum('allocations as participants_total', 'participant_count');

        return $groups->map(fn (EduGroup $g) => $this->item($g))->values();
    }

    public function item(EduGroup $g): array
    {
        $sessions = $g->sessions->values();
        $days = $sessions->count();

        return [
            'id' => $g->id,
            'program_id' => $g->program_id,
            'program' => $g->program?->only(['id', 'title', 'topic_id', 'period_label', 'status']),
            'number' => $g->number,
            'title' => 'Група ' . $g->number,
            'language' => $g->language,
            'total' => (int) ($g->participants_total ?? 0),
            'status' => $g->status,
            'location_id' => $g->location_id,
            'location' => $g->location?->only(['id', 'name', 'kind', 'school_id', 'municipality_id', 'address']),
            'location_text' => $g->location_text,
            'location_label' => $g->location?->name ?? $g->location_text,
            'venue_municipality_id' => $g->venue_municipality_id,
            'venue_municipality' => $g->venueMunicipality?->only(['id', 'name', 'region_id']),
            'trainer_id' => $g->trainer_id,
            'trainer' => $g->trainer?->only(['id', 'full_name', 'full_name_sq', 'languages']),
            'trainer_override' => $g->trainer_override,
            'start_date' => $g->start_date?->format('Y-m-d'),
            'end_date' => $g->end_date?->format('Y-m-d'),
            'sessions' => $sessions->map(fn ($s, $i) => [
                'id' => $s->id,
                'date' => $s->date?->format('Y-m-d'),
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'sort_order' => $s->sort_order,
                'day' => $i + 1,
                'days' => $days,
            ])->values(),
        ];
    }
}
