<?php

namespace Tests\Support;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduGroupSession;
use App\Models\EduLocation;
use App\Models\EduProgram;
use App\Models\EduSchool;
use App\Models\EduTrainer;

/**
 * Обуки outputs (Динамика, group documents): a program with scheduled
 * groups, built on EduProgramHelpers.
 *
 *   Група 1  sq  Хотел Турист (Град Скопје)       Гостивар 3, Зеф Љуш Марку 2   Lumturije Mehmedi  30.06-01.07.2026 9:00-13:00
 *   Група 2  mk  ООУ „Панко Брашнаров“ (Велес)    Панко 1 mk + 1 tr, Тодор Јанев 3   Катерина Димеска   01-02.06.2026 8:30-13:30
 *   Група 3  sq  СОУ „Гостивар“ (Гостивар)        Гостивар 4                       (no trainer, no dates)
 *   Група 4  mk  cancelled                        Тодор Јанев 2
 */
trait EduOutputFixtures
{
    use EduProgramHelpers;

    protected EduProgram $program;

    /** @var array<string, EduSchool> */
    protected array $schools = [];

    /** @var array<int, EduGroup> by number */
    protected array $groups = [];

    protected function buildOutputFixtures(array $programAttributes = []): void
    {
        $this->eduSetUp();
        $this->program = $this->eduProgram($programAttributes + [
            'title' => 'Обука за стручни соработници во основните училишта',
            'staff_type' => 'professional_associates',
            'period_label' => 'јуни 2026',
        ]);

        $this->schools['panko'] = $this->eduSchool('ООУ „Панко Брашнаров“', 'Велес', ['mk', 'tr']);
        $this->schools['todor'] = $this->eduSchool('ООУ „Тодор Јанев“ с.Чашка', 'Чашка');
        $this->schools['gostivar'] = $this->eduSchool('СОУ „Гостивар“/ SHMK „Gostivar“', 'Гостивар', ['mk', 'sq'], EduSchool::LEVEL_SECONDARY);
        $this->schools['gostivar']->update([
            'address' => 'ул. „Букурешка“ бб',
            'address_sq' => 'Rr. „Bukureshka“ pn.',
            'phone_raw' => '042/214-118',
            'director_name' => 'Арбен Шабани',
            'director_contact' => '070111222',
            'email_raw' => 'sougostivar@yahoo.com',
            'schools_mk_email' => 'sou-gostivar@schools.mk',
        ]);
        $this->schools['zef'] = $this->eduSchool('СУГС „Зеф Љуш Марку“', 'Град Скопје', ['sq'], EduSchool::LEVEL_SECONDARY);

        $katerina = $this->trainer('Катерина Димеска', null);
        $lumturije = $this->trainer('Лумтурије Мехмеди', 'Lumturije Mehmedi');

        $hotel = EduLocation::create(['name' => 'Хотел Турист', 'kind' => 'hotel', 'municipality_id' => $this->eduMunicipality('Град Скопје')->id, 'address' => 'ул. Гиго Михајловски 1']);
        $pankoVenue = $this->schoolLocation($this->schools['panko']);
        $gostivarVenue = $this->schoolLocation($this->schools['gostivar']);

        // Created out of order: outputs sort by number.
        $this->groups[2] = $this->group(2, 'mk', $pankoVenue, $katerina, [
            ['panko', 'mk', 1], ['panko', 'tr', 1], ['todor', 'mk', 3],
        ], ['2026-06-01', '2026-06-02'], '08:30', '13:30');
        $this->groups[1] = $this->group(1, 'sq', $hotel, $lumturije, [
            ['gostivar', 'sq', 3], ['zef', 'sq', 2],
        ], ['2026-06-30', '2026-07-01'], '09:00', '13:00');
        $this->groups[3] = $this->group(3, 'sq', $gostivarVenue, null, [['gostivar', 'sq', 4]]);
        $this->groups[4] = $this->group(4, 'mk', null, null, [['todor', 'mk', 2]], [], null, null, 'cancelled');
    }

    protected function trainer(string $name, ?string $nameSq): EduTrainer
    {
        return EduTrainer::create(['full_name' => $name, 'full_name_sq' => $nameSq, 'name_key' => mb_strtolower($name), 'languages' => ['mk']]);
    }

    protected function schoolLocation(EduSchool $school): EduLocation
    {
        return EduLocation::create([
            'name' => $school->display_name,
            'kind' => 'school',
            'school_id' => $school->id,
            'municipality_id' => $school->municipality_id,
            'address' => $school->address,
        ]);
    }

    /** @param list<array{0: string, 1: string, 2: int}> $allocations [school key, language, count] */
    protected function group(int $number, string $language, ?EduLocation $location, ?EduTrainer $trainer, array $allocations,
        array $dates = [], ?string $start = null, ?string $end = null, string $status = 'planned'): EduGroup
    {
        $group = EduGroup::create([
            'program_id' => $this->program->id,
            'number' => $number,
            'language' => $language,
            'location_id' => $location?->id,
            'venue_municipality_id' => $location?->municipality_id,
            'trainer_id' => $trainer?->id,
            'status' => $status,
            'start_date' => $dates[0] ?? null,
            'end_date' => $dates ? end($dates) : null,
        ]);
        foreach ($allocations as $i => [$school, $lang, $count]) {
            EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $this->schools[$school]->id, 'language' => $lang, 'participant_count' => $count, 'sort_order' => $i]);
        }
        foreach ($dates as $i => $date) {
            EduGroupSession::create(['group_id' => $group->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'sort_order' => $i]);
        }

        return $group;
    }

    /** "ООУ „Панко Брашнаров“-Велес (1+1т)" with the school's stored display_name. */
    protected function schoolEntry(string $key, string $municipality, string $count): string
    {
        return $this->schools[$key]->fresh()->display_name . '-' . $municipality . " ({$count})";
    }
}
