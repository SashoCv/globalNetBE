<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Services\Edu\EduProgramCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class GroupBoardApiTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    private EduProgram $program;
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp();
        $this->program = $this->eduProgram(['group_size_min' => 5, 'group_size_max' => 20]);
        foreach (['Велес' => [12, 0, 2], 'Чашка' => [6], 'Кавадарци' => [9, 3]] as $m => $c) {
            $this->schools[$m] = $this->eduSchool("ООУ „{$m}“", $m, ['mk', 'sq']);
            $this->eduCounts($this->program, $this->schools[$m], ...$c);
        }
    }

    /** Demand never changes and is never exceeded: allocated + remaining = demand. */
    private function assertLedger(): void
    {
        $counts = app(EduProgramCounts::class);
        $allocated = $counts->allocated($this->program);
        foreach (EduProgramSchool::where('program_id', $this->program->id)->get() as $row) {
            foreach ($row->counts() as $lang => $demand) {
                $a = $allocated[$row->school_id][$lang] ?? 0;
                $this->assertLessThanOrEqual($demand, $a, "school {$row->school_id} {$lang}");
                $this->assertSame($demand, $a + $counts->remaining($this->program, $row->school_id, $lang));
            }
        }
        if ($this->program->fresh()->isPersons()) {
            foreach (EduGroupSchool::all() as $allocation) {
                $this->assertSame($allocation->participant_count, EduParticipant::where('group_id', $allocation->group_id)
                    ->where('school_id', $allocation->school_id)->where('language', $allocation->language)->count());
            }
        }
    }

    private function group(int $number, string $language = 'mk'): EduGroup
    {
        return EduGroup::create(['program_id' => $this->program->id, 'number' => $number, 'language' => $language]);
    }

    public function test_auto_dry_run_then_save(): void
    {
        $id = $this->program->id;
        $dry = $this->postJson("/api/edu-programs/{$id}/groups/auto")->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonStructure(['groups' => [['id', 'number', 'zone', 'language', 'total', 'undersized', 'oversized', 'warnings', 'allocations' => [['school_id', 'display_name', 'municipality_name', 'language', 'participant_count']], 'venue_municipality_suggestion', 'venue_school_suggestion']], 'kept_groups', 'zone_merges', 'warnings', 'summary']);
        $this->assertSame(0, EduGroup::count());
        $this->assertSame(32, array_sum(array_column($dry->json('groups'), 'total')));

        $saved = $this->postJson("/api/edu-programs/{$id}/groups/auto", ['dry_run' => false])->assertOk()->assertJsonPath('dry_run', false);
        $this->assertSame(count($dry->json('groups')), EduGroup::count());
        $this->assertNotNull($saved->json('groups.0.id'));
        $this->assertLedger();

        $this->postJson("/api/edu-programs/{$id}/groups/auto", ['max_overflow' => -1])->assertStatus(422);

        $index = $this->getJson("/api/edu-programs/{$id}/groups")->assertOk()
            ->assertJsonStructure([['id', 'number', 'title', 'zone', 'language', 'total', 'allocations' => [['id', 'school_id', 'display_name', 'municipality_name', 'language', 'participant_count']], 'location', 'location_text', 'venue_municipality', 'trainer', 'trainer_override', 'sessions', 'start_date', 'end_date', 'status', 'is_locked', 'warnings']]);
        $this->assertSame(32, array_sum(array_column($index->json(), 'total')));
        $this->getJson("/api/edu-programs/{$id}/groups?language=sq")->assertOk()->assertJsonCount(collect($dry->json('groups'))->where('language', 'sq')->count());
        $this->getJson("/api/edu-programs/{$id}/unassigned")->assertOk()->assertJsonPath('schools', [])->assertJsonPath('participants', []);
    }

    public function test_allocations_move_split_and_never_exceed_demand(): void
    {
        $veles = $this->schools['Велес'];
        $g1 = $this->group(1);
        $g2 = $this->group(2);

        // From the unassigned ones, at most what is left.
        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 13])
            ->assertStatus(422)->assertJsonValidationErrors('count');
        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 12])->assertOk()
            ->assertJsonPath('group.total', 12)
            ->assertJsonPath('from_group', null);
        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'tr', 'count' => 2])->assertOk()
            ->assertJsonPath('group.total', 14);
        $this->assertLedger();

        // Move part of a school to another group: the school is split.
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 5, 'from_group_id' => $g1->id])->assertOk()
            ->assertJsonPath('group.total', 5)
            ->assertJsonPath('from_group.total', 9);
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 8, 'from_group_id' => $g1->id])
            ->assertStatus(422)->assertJsonValidationErrors('count');
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $this->schools['Чашка']->id, 'language' => 'mk', 'count' => 1, 'from_group_id' => $g1->id])
            ->assertStatus(422)->assertJsonValidationErrors('school_id');
        $this->assertLedger();

        // The whole rest moves: the source row disappears.
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 7, 'from_group_id' => $g1->id])->assertOk();
        $this->assertSame(['tr'], EduGroupSchool::where('group_id', $g1->id)->pluck('language')->all());
        $this->assertSame(12, EduGroupSchool::where('group_id', $g2->id)->value('participant_count'));
        $this->assertLedger();

        // Edit a count: not above what is left.
        $row = EduGroupSchool::where('group_id', $g2->id)->first();
        $this->putJson("/api/edu-group-schools/{$row->id}", ['participant_count' => 13])->assertStatus(422);
        $this->putJson("/api/edu-group-schools/{$row->id}", ['participant_count' => 10])->assertOk()
            ->assertJsonPath('allocation.participant_count', 10)
            ->assertJsonPath('group.total', 10);
        $this->getJson("/api/edu-programs/{$this->program->id}/unassigned")->assertOk()
            ->assertJsonPath('schools.0.school_id', $veles->id)
            ->assertJsonPath('schools.0.remaining', ['mk' => 2, 'sq' => 0, 'tr' => 0]);
        $this->assertLedger();

        // An invited school (0) can be placed; a school outside the program cannot.
        $invited = $this->eduSchool('ООУ „Покането“', 'Велес');
        $this->eduCounts($this->program, $invited, 0);
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $invited->id, 'language' => 'mk', 'count' => 0])->assertOk()
            ->assertJsonCount(2, 'group.allocations');
        $outsider = $this->eduSchool('ООУ „Надвор“', 'Велес');
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $outsider->id, 'language' => 'mk', 'count' => 0])->assertStatus(422);

        // Delete an allocation: back to unassigned.
        $this->deleteJson("/api/edu-group-schools/{$row->id}")->assertStatus(204);
        $this->assertSame(12, app(EduProgramCounts::class)->remaining($this->program, $veles->id, 'mk'));
        $this->assertLedger();
    }

    public function test_merge_split_renumber_delete(): void
    {
        $veles = $this->schools['Велес'];
        $kav = $this->schools['Кавадарци'];
        $g1 = $this->group(1);
        $g2 = $this->group(2);
        $g3 = $this->group(3, 'sq');
        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 6])->assertOk();
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 6])->assertOk();
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $kav->id, 'language' => 'mk', 'count' => 9])->assertOk();
        $this->postJson("/api/edu-groups/{$g3->id}/allocations", ['school_id' => $kav->id, 'language' => 'sq', 'count' => 3])->assertOk();

        // Merge: counts add up per school and language; another language is a warning.
        $merged = $this->postJson("/api/edu-groups/{$g1->id}/merge", ['group_ids' => [$g2->id, $g3->id]])->assertOk()
            ->assertJsonPath('id', $g1->id)
            ->assertJsonPath('total', 24)
            ->assertJsonPath('merge_warnings.0.code', 'language_differs');
        $this->assertSame(12, EduGroupSchool::where('group_id', $g1->id)->where('school_id', $veles->id)->value('participant_count'));
        $this->assertContains('mixed_language', array_column($merged->json('warnings'), 'code'));
        $this->assertContains('oversized', array_column($merged->json('warnings'), 'code'));
        $this->assertNull(EduGroup::find($g2->id));
        $this->assertLedger();
        $other = $this->eduProgram();
        $foreign = EduGroup::create(['program_id' => $other->id, 'number' => 1, 'language' => 'mk']);
        $this->postJson("/api/edu-groups/{$g1->id}/merge", ['group_ids' => [$foreign->id]])->assertStatus(422);
        $this->postJson("/api/edu-groups/{$g1->id}/merge", ['group_ids' => [$g1->id]])->assertStatus(422);

        // Split: part of the allocations into a new group numbered after the last.
        $velesRow = EduGroupSchool::where('group_id', $g1->id)->where('school_id', $veles->id)->first();
        $kavRow = EduGroupSchool::where('group_id', $g1->id)->where('school_id', $kav->id)->where('language', 'mk')->first();
        $split = $this->postJson("/api/edu-groups/{$g1->id}/split", ['allocations' => [
            ['allocation_id' => $velesRow->id, 'count' => 4],
            ['allocation_id' => $kavRow->id, 'count' => 9],
        ]])->assertOk()
            ->assertJsonPath('group.total', 11)
            ->assertJsonPath('new_group.total', 13)
            ->assertJsonPath('new_group.number', 2)
            ->assertJsonPath('new_group.language', 'mk');
        $this->postJson("/api/edu-groups/{$g1->id}/split", ['allocations' => [['allocation_id' => $velesRow->id, 'count' => 99]]])->assertStatus(422);
        $this->assertLedger();

        // New empty groups, then renumber: no clash with the unique number.
        $g4 = $this->postJson("/api/edu-programs/{$this->program->id}/groups", ['language' => 'sq'])->assertStatus(201)
            ->assertJsonPath('number', 3)->assertJsonPath('total', 0)->assertJsonPath('warnings.0.code', 'empty')->json('id');
        $zone = EduProgramZone::where('program_id', $this->program->id)->where('name', 'Вардарски')->value('id');
        $this->postJson("/api/edu-programs/{$this->program->id}/groups", ['language' => 'mk', 'zone_id' => $other->zones()->value('id')])->assertStatus(422);
        $this->putJson("/api/edu-groups/{$g4}", ['zone_id' => $zone, 'is_locked' => true, 'status' => 'planned', 'comment' => 'x'])->assertOk()
            ->assertJsonPath('zone.name', 'Вардарски')->assertJsonPath('is_locked', true)->assertJsonPath('status', 'planned');
        $this->putJson("/api/edu-groups/{$g4}", ['status' => 'done'])->assertStatus(422);
        EduGroup::whereKey($g4)->update(['number' => 40]);
        $newId = $split->json('new_group.id');

        $renumbered = $this->postJson("/api/edu-programs/{$this->program->id}/groups/renumber", ['order' => [$g4, $newId]])->assertOk();
        $this->assertSame([[1, $g4], [2, $newId], [3, $g1->id]], array_map(fn ($g) => [$g['number'], $g['id']], $renumbered->json()));
        $this->postJson("/api/edu-programs/{$this->program->id}/groups/renumber")->assertOk();
        $this->assertSame([1, 2, 3], EduGroup::where('program_id', $this->program->id)->orderBy('number')->pluck('number')->all());
        $this->postJson("/api/edu-programs/{$this->program->id}/groups/renumber", ['order' => [$foreign->id]])->assertStatus(422);

        // Delete: its participants go back to unassigned.
        $this->deleteJson("/api/edu-groups/{$newId}")->assertStatus(204);
        $this->assertSame(9, app(EduProgramCounts::class)->remaining($this->program, $kav->id, 'mk'));
        $this->assertLedger();
        $this->getJson("/api/edu-groups/{$g1->id}")->assertOk()->assertJsonPath('title', 'Група ' . EduGroup::find($g1->id)->number);
    }

    public function test_persons_mode_moves_people_with_the_counts(): void
    {
        $veles = $this->schools['Велес'];
        foreach (range(1, 6) as $i) {
            EduParticipant::create(['program_id' => $this->program->id, 'school_id' => $veles->id, 'language' => 'mk', 'full_name' => "Лице {$i}", 'name_key' => "лице {$i}"]);
        }
        $this->program->update(['participant_mode' => 'persons']);
        app(EduProgramCounts::class)->syncAll($this->program->fresh());
        $g1 = $this->group(1);
        $g2 = $this->group(2);

        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 7])->assertStatus(422);
        $this->postJson("/api/edu-groups/{$g1->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 6])->assertOk();
        $this->assertSame(6, EduParticipant::where('group_id', $g1->id)->count());
        $this->postJson("/api/edu-groups/{$g2->id}/allocations", ['school_id' => $veles->id, 'language' => 'mk', 'count' => 2, 'from_group_id' => $g1->id])->assertOk();
        $this->assertSame(2, EduParticipant::where('group_id', $g2->id)->count());
        $this->assertLedger();

        $row = EduGroupSchool::where('group_id', $g1->id)->first();
        $this->putJson("/api/edu-group-schools/{$row->id}", ['participant_count' => 1])->assertOk()->assertJsonPath('group.total', 1);
        $this->assertSame(3, EduParticipant::where('program_id', $this->program->id)->whereNull('group_id')->count());
        $this->getJson("/api/edu-programs/{$this->program->id}/unassigned")->assertOk()->assertJsonCount(3, 'participants');
        $this->assertLedger();

        $this->postJson("/api/edu-groups/{$g1->id}/merge", ['group_ids' => [$g2->id]])->assertOk()->assertJsonPath('total', 3);
        $this->assertSame(3, EduParticipant::where('group_id', $g1->id)->count());
        $this->assertLedger();

        $this->deleteJson("/api/edu-groups/{$g1->id}")->assertStatus(204);
        $this->assertSame(6, EduParticipant::where('program_id', $this->program->id)->whereNull('group_id')->count());
        $this->assertLedger();
    }
}
