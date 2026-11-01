<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduLocation;
use App\Models\EduProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\Support\EduTrainerHelpers;
use Tests\TestCase;

class TrainerAssignApiTest extends TestCase
{
    use EduProgramHelpers;
    use EduTrainerHelpers;
    use RefreshDatabase;

    private EduProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp();
        $this->program = $this->eduProgram();
    }

    public function test_candidates_endpoint(): void
    {
        $this->eduTrainer('Ана', ['mk'], ['Велес'], 'Велес');
        $this->eduTrainer('Арбен', ['sq'], ['Велес']);
        $group = $this->eduGroup($this->program, 'Велес');

        $this->getJson("/api/edu-groups/{$group->id}/trainer-candidates")->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([['trainer' => ['id', 'full_name', 'full_name_sq', 'languages', 'is_active', 'home_municipality'], 'trainer_id', 'eligible', 'score', 'matrix', 'reasons', 'problems', 'blocking', 'conflicts', 'load']])
            ->assertJsonPath('0.trainer.full_name', 'Ана')
            ->assertJsonPath('0.score', 3);
        $this->getJson("/api/edu-groups/{$group->id}/trainer-candidates?include_ineligible=1")->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('1.eligible', false)
            ->assertJsonPath('1.blocking', ['language_mismatch']);
        $this->getJson('/api/edu-groups/999999/trainer-candidates')->assertNotFound();
    }

    public function test_manual_assignment_needs_force_for_a_rule_break(): void
    {
        $ok = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $outside = $this->eduTrainer('Битолчанка', ['mk'], ['Битола']);
        $group = $this->eduGroup($this->program, 'Велес');

        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => $outside->id])->assertStatus(422)
            ->assertJsonPath('problems', ['no_hub'])
            ->assertJsonPath('blocking', ['no_hub'])
            ->assertJsonStructure(['message', 'problems', 'blocking', 'conflicts']);
        $this->assertNull($group->fresh()->trainer_id);

        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => $outside->id, 'force' => true])->assertOk()
            ->assertJsonPath('trainer_id', $outside->id)
            ->assertJsonPath('trainer_override', true)
            ->assertJsonPath('trainer.full_name', 'Битолчанка')
            ->assertJsonPath('trainer_check.blocking', ['no_hub']);

        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => $ok->id])->assertOk()
            ->assertJsonPath('trainer_override', false)
            ->assertJsonPath('trainer_check.eligible', true);

        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => null])->assertOk()
            ->assertJsonPath('trainer_id', null)
            ->assertJsonPath('trainer_check', null);
        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => 999999])->assertStatus(422);
    }

    public function test_assigning_a_trainer_to_a_scheduled_group_plans_it(): void
    {
        $trainer = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $location = EduLocation::create(['name' => 'Хотел Белви', 'kind' => 'hotel', 'municipality_id' => $this->eduMunicipality('Велес')->id]);
        $group = $this->eduGroup($this->program, 'Велес', ['2026-06-01', '2026-06-02'], 'mk', ['location_id' => $location->id]);

        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => $trainer->id])->assertOk()->assertJsonPath('status', 'planned');
        $this->putJson("/api/edu-groups/{$group->id}/trainer", ['trainer_id' => null])->assertOk()->assertJsonPath('status', 'draft');
    }

    public function test_auto_assigns_a_single_candidate_and_asks_when_there_are_several(): void
    {
        $ana = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $this->eduTrainer('Бојан', ['mk'], ['Битола']);
        $this->eduTrainer('Вера', ['mk'], ['Битола']);
        $veles = $this->eduGroup($this->program, 'Велес', ['2026-06-01']);
        $bitola = $this->eduGroup($this->program, 'Битола', ['2026-06-01']);
        $struga = $this->eduGroup($this->program, 'Струга', ['2026-06-01']);

        $res = $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto")->assertOk()
            ->assertJsonStructure(['dry_run', 'pick', 'assigned' => [['group_id', 'number', 'trainer_id', 'full_name', 'score', 'reasons', 'problems', 'alternatives']], 'needs_choice' => [['group_id', 'number', 'candidates' => [['trainer_id', 'full_name', 'eligible', 'score', 'reasons', 'problems', 'blocking', 'load']]]], 'no_candidate' => [['group_id', 'number', 'candidates']], 'skipped', 'summary', 'note'])
            ->assertJsonPath('assigned.0.group_id', $veles->id)
            ->assertJsonPath('assigned.0.trainer_id', $ana->id)
            ->assertJsonPath('needs_choice.0.group_id', $bitola->id)
            ->assertJsonPath('no_candidate.0.group_id', $struga->id)
            ->assertJsonPath('note', null);
        $this->assertCount(2, $res->json('needs_choice.0.candidates'));
        $this->assertSame($ana->id, $veles->fresh()->trainer_id);
        $this->assertFalse($veles->fresh()->trainer_override);
        $this->assertNull($bitola->fresh()->trainer_id);

        // pick=best takes the top one; groups with a trainer are skipped unless overwrite.
        $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto", ['pick' => 'best'])->assertOk()
            ->assertJsonPath('summary.assigned', 1)
            ->assertJsonPath('assigned.0.group_id', $bitola->id)
            ->assertJsonPath('assigned.0.full_name', 'Бојан')
            ->assertJsonPath('skipped', 1);
        $this->assertNotNull($bitola->fresh()->trainer_id);

        // Manual programs have no automatic assignment.
        $this->program->update(['trainer_assignment_mode' => 'manual']);
        $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto")->assertStatus(409);
    }

    public function test_auto_never_double_books_within_one_run(): void
    {
        $ana = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $first = $this->eduGroup($this->program, 'Велес', ['2026-06-01', '2026-06-02']);
        $second = $this->eduGroup($this->program, 'Велес', ['2026-06-02', '2026-06-03']);
        $third = $this->eduGroup($this->program, 'Велес', ['2026-06-04']);

        $res = $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto", ['pick' => 'best'])->assertOk()
            ->assertJsonPath('summary.assigned', 2)
            ->assertJsonPath('no_candidate.0.group_id', $second->id)
            ->assertJsonPath('no_candidate.0.candidates.0.blocking', ['conflict']);
        $this->assertSame($ana->id, $first->fresh()->trainer_id);
        $this->assertNull($second->fresh()->trainer_id);
        $this->assertSame($ana->id, $third->fresh()->trainer_id);
        $this->assertSame(-1, $res->json('assigned.1.score'));   // −1 for the group taken earlier in the run
    }

    public function test_without_dates_only_the_load_is_balanced_and_a_note_is_added(): void
    {
        $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $this->eduTrainer('Бојан', ['mk'], ['Велес']);
        $groups = collect(range(1, 4))->map(fn () => $this->eduGroup($this->program, 'Велес'));

        $res = $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto", ['pick' => 'best', 'dry_run' => true])->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('summary.assigned', 4)
            ->assertJsonPath('summary.undated', 4);
        $this->assertNotNull($res->json('note'));
        $this->assertSame(['Ана', 'Бојан', 'Ана', 'Бојан'], array_column($res->json('assigned'), 'full_name'));
        $this->assertSame(0, EduGroup::whereNotNull('trainer_id')->count(), 'dry run saves nothing');

        $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto", ['pick' => 'best'])->assertOk();
        $this->assertSame([2, 2], EduGroup::whereIn('id', $groups->pluck('id'))->get()->countBy('trainer_id')->values()->all());
    }

    public function test_overwrite_reassigns_but_keeps_forced_choices(): void
    {
        $ana = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $outside = $this->eduTrainer('Битолчанка', ['mk'], ['Битола']);
        $plain = $this->eduGroup($this->program, 'Велес', [], 'mk', ['trainer_id' => $outside->id]);
        $forced = $this->eduGroup($this->program, 'Велес', [], 'mk', ['trainer_id' => $outside->id, 'trainer_override' => true]);

        $this->postJson("/api/edu-programs/{$this->program->id}/trainers/auto", ['overwrite' => true])->assertOk()
            ->assertJsonPath('summary.groups', 1)
            ->assertJsonPath('skipped', 1);
        $this->assertSame($ana->id, $plain->fresh()->trainer_id);
        $this->assertSame($outside->id, $forced->fresh()->trainer_id);
    }

    public function test_trainer_groups_and_upcoming_count(): void
    {
        $ana = $this->eduTrainer('Ана', ['mk'], ['Велес']);
        $this->eduGroup($this->program, 'Велес', ['2026-12-07', '2026-12-08'], 'mk', ['trainer_id' => $ana->id]);
        $this->eduGroup($this->program, 'Велес', [], 'mk', ['trainer_id' => $ana->id]);
        $this->eduGroup($this->program, 'Велес', ['2026-06-01'], 'mk', ['trainer_id' => $ana->id]);                         // past
        $this->eduGroup($this->program, 'Велес', ['2026-12-09'], 'mk', ['trainer_id' => $ana->id, 'status' => 'cancelled']);

        $this->getJson('/api/edu-trainers')->assertOk()->assertJsonPath('0.upcoming_groups_count', 2);
        $this->getJson("/api/edu-trainers/{$ana->id}")->assertOk()->assertJsonPath('upcoming_groups_count', 2);

        $res = $this->getJson("/api/edu-trainers/{$ana->id}/groups")->assertOk()->assertJsonCount(4)
            ->assertJsonStructure([['id', 'program_id', 'program' => ['id', 'title'], 'number', 'title', 'language', 'total', 'status', 'location', 'location_text', 'location_label', 'venue_municipality', 'trainer', 'start_date', 'end_date', 'sessions' => [['id', 'date', 'start_time', 'end_time', 'day', 'days']]]]);
        $this->assertSame(['2026-06-01', '2026-12-07', '2026-12-09', null], array_column($res->json(), 'start_date'));
        $this->getJson("/api/edu-trainers/{$ana->id}/groups?from=2026-12-01&to=2026-12-31&status=draft,planned")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.sessions.1.date', '2026-12-08')
            ->assertJsonPath('0.sessions.1.day', 2);
    }
}
