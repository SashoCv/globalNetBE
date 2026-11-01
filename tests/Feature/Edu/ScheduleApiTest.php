<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduGroupSession;
use App\Models\EduLocation;
use App\Models\EduProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\Support\EduTrainerHelpers;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use EduProgramHelpers;
    use EduTrainerHelpers;
    use RefreshDatabase;

    private EduProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp();
        $this->program = $this->eduProgram(['default_start_time' => '08:30', 'default_end_time' => '13:30', 'default_days' => 2]);
    }

    private function schedule(EduGroup $group, array $body)
    {
        return $this->putJson("/api/edu-groups/{$group->id}/schedule", $body);
    }

    public function test_school_creates_or_links_a_location(): void
    {
        $school = $this->eduSchool('ООУ „Блаже Конески“', 'Велес');
        $school->update(['address' => 'ул. Борис Кидрич 1', 'phones' => ['043211111']]);
        $group = $this->eduGroup($this->program, 'Чашка');

        $this->schedule($group, ['school_id' => $school->id])->assertOk()
            ->assertJsonPath('location.kind', 'school')
            ->assertJsonPath('location.school_id', $school->id)
            ->assertJsonPath('location.name', $school->display_name)
            ->assertJsonPath('location.address', 'ул. Борис Кидрич 1')
            ->assertJsonPath('location_text', null)
            ->assertJsonPath('venue_municipality_id', $school->municipality_id);
        $this->assertSame(1, EduLocation::count());

        $other = $this->eduGroup($this->program, 'Велес');
        $this->schedule($other, ['school_id' => $school->id])->assertOk();
        $this->assertSame(1, EduLocation::count(), 'the same school location is reused');
        $this->assertSame($group->fresh()->location_id, $other->fresh()->location_id);
    }

    public function test_location_id_free_text_and_clearing(): void
    {
        $hotel = EduLocation::create(['name' => 'Хотел Метропол', 'kind' => 'hotel', 'municipality_id' => $this->eduMunicipality('Охрид')->id]);
        $group = $this->eduGroup($this->program, null);

        $this->schedule($group, ['location_id' => $hotel->id])->assertOk()
            ->assertJsonPath('location_id', $hotel->id)
            ->assertJsonPath('venue_municipality.name', 'Охрид');

        $this->schedule($group, ['location_text' => 'Сала на општината', 'venue_municipality_id' => $this->eduMunicipality('Струга')->id])->assertOk()
            ->assertJsonPath('location_id', null)
            ->assertJsonPath('location_text', 'Сала на општината')
            ->assertJsonPath('venue_municipality.name', 'Струга');

        $this->schedule($group, ['location_text' => 'Дом на културата', 'save_location' => true])->assertOk()
            ->assertJsonPath('location.name', 'Дом на културата')
            ->assertJsonPath('location.kind', 'other')
            ->assertJsonPath('location_text', null);

        $this->schedule($group, ['location_id' => $hotel->id, 'location_text' => 'x'])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->schedule($this->eduGroup($this->program, null), ['location_text' => 'Без општина'])->assertStatus(422)->assertJsonValidationErrors('venue_municipality_id');

        $this->schedule($group, ['location_id' => null])->assertOk()
            ->assertJsonPath('location_id', null)
            ->assertJsonPath('location_text', null);
    }

    public function test_sessions_are_generated_from_start_date_and_days(): void
    {
        $group = $this->eduGroup($this->program, 'Велес');

        $res = $this->schedule($group, ['start_date' => '2026-06-01'])->assertOk()
            ->assertJsonPath('start_date', '2026-06-01')
            ->assertJsonPath('end_date', '2026-06-02')
            ->assertJsonPath('sessions.0.start_time', '08:30')
            ->assertJsonPath('sessions.0.end_time', '13:30')
            ->assertJsonPath('sessions.1.date', '2026-06-02')
            ->assertJsonPath('sessions.1.sort_order', 2);
        $this->assertCount(2, $res->json('sessions'));

        // Regenerated, not added to; times may be given.
        $res = $this->schedule($group, ['start_date' => '2026-06-08', 'days' => 3, 'start_time' => '09:00', 'end_time' => '14:00'])->assertOk()
            ->assertJsonPath('start_date', '2026-06-08')
            ->assertJsonPath('end_date', '2026-06-10')
            ->assertJsonPath('sessions.2.start_time', '09:00');
        $this->assertSame(3, EduGroupSession::where('group_id', $group->id)->count());

        // Only new times: the days stay.
        $this->schedule($group, ['start_time' => '10:00', 'end_time' => '15:00'])->assertOk()
            ->assertJsonPath('sessions.0.date', '2026-06-08')
            ->assertJsonPath('sessions.0.start_time', '10:00');

        $this->schedule($group, ['start_time' => '15:00', 'end_time' => '10:00'])->assertStatus(422);
        $this->schedule($group, ['start_date' => '2026-06-01', 'dates' => ['2026-06-02']])->assertStatus(422);
        $this->schedule($group, ['start_date' => '01.06.2026'])->assertStatus(422);
    }

    public function test_weekends_are_skipped(): void
    {
        $group = $this->eduGroup($this->program, 'Велес');

        $this->schedule($group, ['start_date' => '2026-06-05', 'days' => 2])->assertOk()   // Friday
            ->assertJsonPath('sessions.0.date', '2026-06-05')
            ->assertJsonPath('sessions.1.date', '2026-06-08')
            ->assertJsonPath('end_date', '2026-06-08');

        $res = $this->schedule($group, ['start_date' => '2026-06-06', 'days' => 1])->assertOk()   // Saturday → Monday
            ->assertJsonPath('start_date', '2026-06-08');
        $this->assertContains('start_moved', array_column($res->json('warnings'), 'code'));
    }

    public function test_explicit_dates_and_sessions(): void
    {
        $group = $this->eduGroup($this->program, 'Велес');

        $this->schedule($group, ['dates' => ['2026-06-10', '2026-06-03']])->assertOk()
            ->assertJsonPath('start_date', '2026-06-03')
            ->assertJsonPath('end_date', '2026-06-10')
            ->assertJsonPath('sessions.0.date', '2026-06-03');

        $this->schedule($group, ['sessions' => [
            ['date' => '2026-06-15', 'start_time' => '09:00', 'end_time' => '12:00'],
            ['date' => '2026-06-16'],
        ]])->assertOk()
            ->assertJsonPath('sessions.0.start_time', '09:00')
            ->assertJsonPath('sessions.1.start_time', '08:30');

        $res = $this->schedule($group, ['dates' => ['2026-06-13']])->assertOk();   // a Saturday, kept but flagged
        $this->assertContains('weekend', array_column($res->json('warnings'), 'code'));

        $this->schedule($group, ['dates' => ['2026-06-03', '2026-06-03']])->assertStatus(422);

        $this->schedule($group, ['sessions' => []])->assertOk()
            ->assertJsonPath('sessions', [])
            ->assertJsonPath('start_date', null)
            ->assertJsonPath('end_date', null);
    }

    public function test_status_becomes_planned_with_trainer_place_and_dates(): void
    {
        $trainer = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $school = $this->eduSchool('ООУ „Кочо Рацин“', 'Велес');
        $group = $this->eduGroup($this->program, 'Велес');

        $this->schedule($group, ['start_date' => '2026-06-01'])->assertOk()->assertJsonPath('status', 'draft');
        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => $trainer->id])->assertOk()->assertJsonPath('status', 'draft');
        $this->schedule($group, ['school_id' => $school->id])->assertOk()->assertJsonPath('status', 'planned');
        $this->schedule($group, ['sessions' => []])->assertOk()->assertJsonPath('status', 'draft');

        // Held / cancelled groups keep their status.
        $group->update(['status' => 'cancelled']);
        $this->schedule($group, ['start_date' => '2026-06-01'])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_move_keeps_the_gaps_between_days(): void
    {
        $group = $this->eduGroup($this->program, 'Велес');
        $this->schedule($group, ['dates' => ['2026-06-01', '2026-06-02', '2026-06-04']])->assertOk();
        $ids = EduGroupSession::where('group_id', $group->id)->orderBy('date')->pluck('id')->all();

        $this->patchJson("/api/edu-groups/{$group->id}/move", ['day_offset' => 1])->assertOk()
            ->assertJsonPath('day_offset', 1)
            ->assertJsonPath('start_date', '2026-06-02')
            ->assertJsonPath('end_date', '2026-06-05')
            ->assertJsonPath('sessions.1.date', '2026-06-03')
            ->assertJsonPath('sessions.0.start_time', '08:30');
        $this->assertSame($ids, EduGroupSession::where('group_id', $group->id)->orderBy('date')->pluck('id')->all(), 'sessions keep their ids');

        $this->patchJson("/api/edu-groups/{$group->id}/move", ['start_date' => '2026-06-15'])->assertOk()
            ->assertJsonPath('day_offset', 13)
            ->assertJsonPath('end_date', '2026-06-18');
        $this->patchJson("/api/edu-groups/{$group->id}/move", ['day_offset' => -14])->assertOk()
            ->assertJsonPath('start_date', '2026-06-01')
            ->assertJsonPath('sessions.2.date', '2026-06-04');

        $this->patchJson('/api/edu-groups/' . $this->eduGroup($this->program, 'Велес')->id . '/move', ['day_offset' => 1])->assertStatus(422);
        $this->patchJson("/api/edu-groups/{$group->id}/move", [])->assertStatus(422);
    }

    public function test_a_schedule_change_rechecks_the_trainer(): void
    {
        $trainer = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $other = $this->eduProgram(['title' => 'Друга']);
        $this->eduGroup($other, 'Велес', ['2026-06-02'], 'mk', ['trainer_id' => $trainer->id]);
        $group = $this->eduGroup($this->program, 'Велес', [], 'mk', ['trainer_id' => $trainer->id]);

        $res = $this->schedule($group, ['start_date' => '2026-06-01'])->assertOk()->assertJsonPath('trainer_id', $trainer->id);
        $this->assertContains('trainer_conflict', array_column($res->json('warnings'), 'code'));

        $res = $this->schedule($group, ['location_text' => 'Сала', 'venue_municipality_id' => $this->eduMunicipality('Битола')->id])->assertOk()
            ->assertJsonPath('trainer_id', $trainer->id);
        $this->assertContains('trainer_no_hub', array_column($res->json('warnings'), 'code'));
    }

    public function test_calendar_index(): void
    {
        $trainer = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $school = $this->eduSchool('ООУ „Гоце Делчев“', 'Велес');
        $this->eduCounts($this->program, $school, 12);
        $a = $this->eduGroup($this->program, 'Велес', ['2026-06-01', '2026-06-02'], 'mk', ['trainer_id' => $trainer->id, 'location_text' => 'Сала']);
        EduGroupSchool::create(['group_id' => $a->id, 'school_id' => $school->id, 'language' => 'mk', 'participant_count' => 12]);
        $other = $this->eduProgram(['title' => 'Втора програма']);
        $b = $this->eduGroup($other, 'Битола', ['2026-06-30', '2026-07-01'], 'sq', ['status' => 'planned']);
        $this->eduGroup($this->program, 'Велес', ['2026-08-01']);
        $this->eduGroup($this->program, 'Велес');

        $res = $this->getJson('/api/edu-schedule?from=2026-06-01&to=2026-06-30')->assertOk()
            ->assertJsonStructure([
                'from', 'to',
                'groups' => [['id', 'program_id', 'program' => ['id', 'title'], 'number', 'title', 'language', 'total', 'status', 'location', 'location_text', 'location_label', 'venue_municipality', 'trainer', 'trainer_override', 'start_date', 'end_date', 'sessions' => [['id', 'date', 'start_time', 'end_time', 'sort_order', 'day', 'days']]]],
                'sessions' => [['id', 'group_id', 'program_id', 'date', 'start_time', 'end_time', 'day', 'days']],
                'totals' => ['groups', 'sessions', 'participants', 'by_status', 'by_language'],
            ])
            ->assertJsonPath('totals.groups', 2)
            ->assertJsonPath('totals.sessions', 3)
            ->assertJsonPath('totals.participants', 12)
            ->assertJsonPath('groups.0.id', $a->id)
            ->assertJsonPath('groups.0.total', 12)
            ->assertJsonPath('groups.0.location_label', 'Сала')
            ->assertJsonPath('groups.0.trainer.full_name', 'Ана')
            ->assertJsonPath('groups.1.program.title', 'Втора програма');
        $this->assertCount(2, $res->json('groups.1.sessions'), 'a group keeps all its sessions');
        $this->assertSame(['2026-06-01', '2026-06-02', '2026-06-30'], array_column($res->json('sessions'), 'date'));

        $q = '/api/edu-schedule?from=2026-06-01&to=2026-07-31';
        $this->getJson("{$q}&program_id={$other->id}")->assertOk()->assertJsonPath('totals.groups', 1);
        $this->getJson("{$q}&trainer_id={$trainer->id}")->assertOk()->assertJsonPath('totals.groups', 1);
        $this->getJson("{$q}&region_id={$this->eduMunicipality('Битола')->region_id}")->assertOk()->assertJsonPath('groups.0.id', $b->id);
        $this->getJson("{$q}&school_id={$school->id}")->assertOk()->assertJsonPath('totals.groups', 1);
        $this->getJson("{$q}&status=planned")->assertOk()->assertJsonPath('totals.groups', 1);
        $this->getJson("{$q}&language=sq")->assertOk()->assertJsonPath('totals.groups', 1);

        $this->getJson('/api/edu-schedule?from=2026-06-01')->assertStatus(422);
        $this->getJson('/api/edu-schedule?from=2026-06-30&to=2026-06-01')->assertStatus(422);
        $this->getJson('/api/edu-schedule?from=2026-01-01&to=2028-01-01')->assertStatus(422);
    }
}
