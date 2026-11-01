<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Support\Edu\GroupDocumentLabels;
use Illuminate\Support\Collection;

/**
 * Обуки: the $group array of resources/views/pdf/edu-group.blade.php (step 11).
 *
 * Programs in "persons" mode list the group's participants; "counts" mode
 * lists the schools with their counts plus, optionally, as many empty
 * numbered rows as the group has participants (to be filled in by hand).
 * With signatures, one "Потпис {dd.mm}" column per session day.
 *
 * Albanian groups are printed with bilingual labels ("Обучувач / Trajner",
 * decision 4): $data['labels'] is GroupDocumentLabels::make(), used by both
 * the PDF views and GroupDocxRenderer.
 */
class GroupDocumentBuilder
{
    public function __construct(private EduGroupOutput $output)
    {
    }

    /**
     * @param  array{signatures?: bool, blank_rows?: bool, bilingual?: ?bool}  $options
     *         signatures and blank_rows default to true; bilingual null (auto) = Albanian groups
     */
    public function build(EduGroup $group, EduProgram $program, array $options = []): array
    {
        return $this->buildMany(collect([$group]), $program, $options)[0];
    }

    /**
     * @param  Collection<int, EduGroup>  $groups
     * @return list<array<string, mixed>>
     */
    public function buildMany(Collection $groups, EduProgram $program, array $options = []): array
    {
        $signatures = $options['signatures'] ?? true;
        $blankRows = $options['blank_rows'] ?? true;
        $bilingual = $options['bilingual'] ?? null;
        $program->loadMissing('topic');
        $groups->each(fn (EduGroup $g) => $this->output->load($g));

        $participants = $program->isPersons() && $groups->isNotEmpty()
            ? EduParticipant::whereIn('group_id', $groups->pluck('id'))
                ->with(['school.municipality', 'municipality'])
                ->orderBy('full_name')->orderBy('id')
                ->get()->groupBy('group_id')
            : collect();

        return $groups->values()->map(function (EduGroup $group) use ($program, $participants, $signatures, $blankRows, $bilingual) {
            $isBilingual = GroupDocumentLabels::bilingual($group->language, $bilingual);
            $labels = GroupDocumentLabels::make($isBilingual);
            $schedule = $this->output->schedule($group);
            $schools = $this->output->schools($group);
            $total = $this->output->total($group);

            $data = [
                'program_title' => (string) $program->title,
                'topic' => $program->topic?->title,
                'number' => (int) $group->number,
                'staff_label' => $labels['staff'][$program->staff_type] ?? EduProgram::STAFF_LABELS[$program->staff_type] ?? null,
                'location' => $this->output->place($group),
                'address' => $this->output->address($group),
                'city' => $this->output->city($group),
                'language' => $labels['languages'][$group->language] ?? $this->output->languageName($group->language),
                'trainer' => $this->output->trainerName($group),
                'dates' => $schedule['dates'],
                'time' => $schedule['time'],
                'signature_days' => $signatures ? $this->signatureDays($group, $program, $labels['day'] ?? null) : [],
                'bilingual' => $isBilingual,
                'labels' => $labels,
            ];

            if ($program->isPersons()) {
                $order = array_flip(array_column($schools, 'school_id'));
                $data['mode'] = 'persons';
                $data['participants'] = ($participants->get($group->id) ?? collect())
                    ->sortBy(fn (EduParticipant $p) => [$order[$p->school_id] ?? PHP_INT_MAX, mb_strtolower((string) $p->full_name)])
                    ->values()
                    ->map(fn (EduParticipant $p) => [
                        'name' => (string) $p->full_name,
                        'school' => $p->school?->display_name ?? $p->school_text,
                        'municipality' => $p->municipality?->name ?? $p->school?->municipality?->name,
                        'email' => $p->email,
                        'phone' => $p->phone,
                    ])->all();
            } else {
                $data['mode'] = 'counts';
                $data['schools'] = array_map(fn ($s) => [
                    'school' => $s['school'],
                    'municipality' => $s['municipality'],
                    'count' => $s['count'],
                    'count_label' => $s['count_label'],
                ], $schools);
                $data['blank_rows'] = $blankRows ? $total : 0;
                $data['total'] = $total;
            }

            return $data;
        })->all();
    }

    /**
     * "01.06", "02.06" per session day; without a schedule, the program's
     * default number of days as "1. ден", "2. ден" ($dayLabel, e.g.
     * "1. ден / Dita 1" for bilingual documents).
     *
     * @return list<string>
     */
    public function signatureDays(EduGroup $group, EduProgram $program, ?string $dayLabel = null): array
    {
        $dates = $group->sessions->pluck('date')->filter()
            ->map(fn ($d) => $d->format('Y-m-d'))->unique()->sort()->values();
        if ($dates->isNotEmpty()) {
            return $dates->map(fn ($d) => DateRangeFormatter::dayMonth($d))->all();
        }

        $dayLabel ??= ':n. ден';

        return array_map(fn ($i) => GroupDocumentLabels::number($dayLabel, $i), range(1, max(1, (int) ($program->default_days ?: 1))));
    }

    /** "grupa-3-{slug}.pdf" / ".docx" */
    public function filename(EduGroup $group, EduProgram $program, string $extension = 'pdf'): string
    {
        return 'grupa-' . $group->number . '-' . $this->output->slug($program) . '.' . $extension;
    }

    /** "grupi-{slug}-2026-06-01.pdf" / ".docx" */
    public function filenameMany(EduProgram $program, string $extension = 'pdf'): string
    {
        return 'grupi-' . $this->output->slug($program) . '-' . now()->format('Y-m-d') . '.' . $extension;
    }

    /**
     * Labels for the parts shared by all groups of one document (title,
     * footer): bilingual when any group is.
     *
     * @param  list<array<string, mixed>>  $groups  the build() / buildMany() arrays
     */
    public static function documentLabels(array $groups): array
    {
        return GroupDocumentLabels::make(collect($groups)->contains(fn ($g) => !empty($g['bilingual'])));
    }
}
