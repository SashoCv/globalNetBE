<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduProgram;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Collection;

/**
 * Обуки: the JSON shape of a group and its warnings (size outside
 * min..max, empty group, participants of another grouping language).
 */
class EduGroupPresenter
{
    public const RELATIONS = [
        'zone:id,name,sort_order',
        'allocations.school:id,display_name,municipality_id,languages',
        'allocations.school.municipality:id,name,hub_municipality_id',
        'location:id,name,kind,school_id,municipality_id,address',
        'venueMunicipality:id,name,hub_municipality_id,part_of_skopje',
        'trainer:id,full_name,full_name_sq,languages',
        'sessions:id,group_id,date,start_time,end_time,sort_order',
    ];

    /** @param Collection<int, EduGroup>|EduGroup $groups */
    public function load(Collection|EduGroup $groups): void
    {
        $groups->load(self::RELATIONS);
    }

    public function payload(EduGroup $group, EduProgram $program): array
    {
        if (!$group->relationLoaded('allocations')) {
            $group->load(self::RELATIONS);
        }
        $allocations = $group->allocations->map(fn ($a) => [
            'id' => $a->id,
            'school_id' => $a->school_id,
            'display_name' => $a->school?->display_name,
            'municipality_id' => $a->school?->municipality_id,
            'municipality_name' => $a->school?->municipality?->name,
            'hub_municipality_id' => $a->school?->municipality?->hub_municipality_id,
            'language' => $a->language,
            'participant_count' => $a->participant_count,
            'sort_order' => $a->sort_order,
        ])->values();

        return [
            'id' => $group->id,
            'program_id' => $group->program_id,
            'number' => $group->number,
            'title' => 'Група ' . $group->number,
            'zone_id' => $group->zone_id,
            'zone' => $group->zone ? ['id' => $group->zone->id, 'name' => $group->zone->name] : null,
            'language' => $group->language,
            'total' => (int) $allocations->sum('participant_count'),
            'allocations' => $allocations,
            'location_id' => $group->location_id,
            'location' => $group->location?->only(['id', 'name', 'kind', 'school_id', 'municipality_id', 'address']),
            'location_text' => $group->location_text,
            'venue_municipality_id' => $group->venue_municipality_id,
            'venue_municipality' => $group->venueMunicipality?->only(['id', 'name', 'hub_municipality_id', 'part_of_skopje']),
            'trainer_id' => $group->trainer_id,
            'trainer' => $group->trainer?->only(['id', 'full_name', 'full_name_sq', 'languages']),
            'trainer_override' => $group->trainer_override,
            'sessions' => $group->sessions->map(fn ($s) => [
                'id' => $s->id,
                'date' => $s->date?->format('Y-m-d'),
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'sort_order' => $s->sort_order,
            ])->values(),
            'start_date' => $group->start_date?->format('Y-m-d'),
            'end_date' => $group->end_date?->format('Y-m-d'),
            'status' => $group->status,
            'is_locked' => $group->is_locked,
            'comment' => $group->comment,
            'warnings' => $this->warnings($group, $program),
        ];
    }

    /** @return list<array{code: string, message: string}> */
    public function warnings(EduGroup $group, EduProgram $program): array
    {
        $total = (int) $group->allocations->sum('participant_count');
        $out = [];
        if ($total === 0) {
            $out[] = ['code' => 'empty', 'message' => 'Групата нема учесници.'];
        } elseif ($total < $program->group_size_min) {
            $out[] = ['code' => 'undersized', 'message' => "Групата има {$total} учесници, под минимумот {$program->group_size_min}."];
        } elseif ($total > $program->group_size_max) {
            $out[] = ['code' => 'oversized', 'message' => "Групата има {$total} учесници, над максимумот {$program->group_size_max}."];
        }

        $other = $group->allocations
            ->filter(fn ($a) => $a->participant_count > 0 && $program->groupLanguage($a->language) !== $group->language)
            ->map(fn ($a) => EduLanguage::LABELS[$a->language] ?? $a->language)
            ->unique()->values();
        if ($other->isNotEmpty()) {
            $label = EduLanguage::LABELS[$group->language] ?? $group->language;
            $out[] = ['code' => 'mixed_language', 'message' => "Групата е на {$label} јазик, а има учесници што се групираат на друг јазик ({$other->implode(', ')})."];
        }

        return $out;
    }
}
