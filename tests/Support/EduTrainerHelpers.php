<?php

namespace Tests\Support;

use App\Models\EduGroup;
use App\Models\EduGroupSession;
use App\Models\EduProgram;
use App\Models\EduTrainer;

/**
 * Обуки tests: trainers with their matrix rows and groups with sessions.
 * Needs EduProgramHelpers (seeded geography).
 */
trait EduTrainerHelpers
{
    /**
     * @param  list<string>  $hubs  default-matrix centres (municipality names)
     * @param  array<int, list<string>>  $topicHubs  topic id => centres
     * @param  list<int>  $topics  topic lists the trainer is on
     */
    protected function eduTrainer(string $name, array $languages = ['mk'], array $hubs = [], ?string $home = null, array $topicHubs = [], array $topics = [], bool $active = true): EduTrainer
    {
        $trainer = EduTrainer::create([
            'full_name' => $name,
            'name_key' => mb_strtolower($name),
            'languages' => $languages,
            'home_municipality_id' => $home ? $this->eduMunicipality($home)->id : null,
            'is_active' => $active,
        ]);
        foreach ($hubs as $hub) {
            $trainer->hubs()->create(['hub_municipality_id' => $this->eduMunicipality($hub)->id, 'topic_id' => null]);
        }
        foreach ($topicHubs as $topicId => $list) {
            foreach ($list as $hub) {
                $trainer->hubs()->create(['hub_municipality_id' => $this->eduMunicipality($hub)->id, 'topic_id' => $topicId]);
            }
        }
        if ($topics) {
            $trainer->topics()->sync($topics);
        }

        return $trainer->fresh();
    }

    /**
     * @param  list<string|array{0: string, 1: string, 2: string}>  $sessions  dates or [date, start, end]
     */
    protected function eduGroup(EduProgram $program, ?string $venue = 'Велес', array $sessions = [], string $language = 'mk', array $attributes = []): EduGroup
    {
        $number = (int) EduGroup::where('program_id', $program->id)->max('number') + 1;
        $group = EduGroup::create($attributes + [
            'program_id' => $program->id,
            'number' => $number,
            'language' => $language,
            'venue_municipality_id' => $venue ? $this->eduMunicipality($venue)->id : null,
        ]);
        foreach (array_values($sessions) as $i => $s) {
            [$date, $start, $end] = is_array($s) ? $s : [$s, '08:30', '13:30'];
            EduGroupSession::create(['group_id' => $group->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'sort_order' => $i + 1]);
        }
        if ($sessions) {
            $dates = array_map(fn ($s) => is_array($s) ? $s[0] : $s, $sessions);
            sort($dates);
            $group->update(['start_date' => $dates[0], 'end_date' => end($dates)]);
        }

        return $group->fresh();
    }
}
