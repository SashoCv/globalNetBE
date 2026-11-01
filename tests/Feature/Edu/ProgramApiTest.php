<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduImport;
use App\Models\EduLocation;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Models\EduProgramZoneMunicipality;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class ProgramApiTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp();
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Обука за наставници',
            'school_level' => 'primary',
            'staff_type' => 'professional_associates',
            'group_size_min' => 11,
            'group_size_max' => 25,
        ];
    }

    public function test_create_program_with_defaults_and_zones(): void
    {
        $title = str_repeat('Долг наслов на програма за обука на стручни соработници. ', 8);
        $this->assertGreaterThan(255, mb_strlen(trim($title)));
        $topic = EduTopic::create(['title' => 'Тема']);

        $response = $this->postJson('/api/edu-programs', $this->valid([
            'title' => $title,
            'topic_id' => $topic->id,
            'default_start_time' => '08:30',
            'default_end_time' => '13:30',
            'period_label' => 'јуни 2026',
        ]))->assertStatus(201);

        $response->assertJsonPath('title', trim($title))
            ->assertJsonPath('school_level', 'primary')
            ->assertJsonPath('staff_type', 'professional_associates')
            ->assertJsonPath('staff_label', 'Стручни соработници')
            ->assertJsonPath('staff_plural', 'стручни соработници')
            ->assertJsonPath('trainer_assignment_mode', 'auto')
            ->assertJsonPath('participant_mode', 'counts')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('default_days', 2)
            ->assertJsonPath('default_start_time', '08:30')
            ->assertJsonPath('language_map', ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'mk'])
            ->assertJsonPath('topic.id', $topic->id)
            ->assertJsonPath('stats.schools', 0)
            ->assertJsonPath('stats.groups', 0)
            ->assertJsonStructure(['stats' => ['schools', 'participants_total', 'by_language' => ['mk', 'sq', 'tr'], 'assigned', 'unassigned', 'groups', 'groups_without_trainer', 'groups_without_dates', 'warnings_count']]);

        $program = EduProgram::find($response->json('id'));
        $this->assertSame(User::first()->id, $program->created_by);

        // One zone per region, every municipality for every language.
        $this->assertSame(8, EduProgramZone::where('program_id', $program->id)->count());
        $this->assertSame(81, EduProgramZoneMunicipality::where('program_id', $program->id)->where('language', '*')->count());
        $this->assertSame(['Скопски', 'Североисточен'], EduProgramZone::where('program_id', $program->id)->orderBy('sort_order')->limit(2)->pluck('name')->all());

        // A language map entry overrides the default, the others stay.
        $this->postJson('/api/edu-programs', $this->valid(['language_map' => ['tr' => 'sq']]))->assertStatus(201)
            ->assertJsonPath('language_map', ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'sq']);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/edu-programs', [])->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'school_level', 'staff_type', 'group_size_min', 'group_size_max']);
        $this->postJson('/api/edu-programs', $this->valid(['school_level' => 'university', 'staff_type' => 'directors']))->assertStatus(422)
            ->assertJsonValidationErrors(['school_level', 'staff_type']);
        $this->postJson('/api/edu-programs', $this->valid(['group_size_min' => 20, 'group_size_max' => 10]))->assertStatus(422)
            ->assertJsonValidationErrors('group_size_max');
        $this->postJson('/api/edu-programs', $this->valid(['group_size_min' => 15, 'group_size_max' => 15]))->assertStatus(201);
        $this->postJson('/api/edu-programs', $this->valid(['language_map' => ['tr' => 'de']]))->assertStatus(422)
            ->assertJsonValidationErrors('language_map.tr');
        $this->postJson('/api/edu-programs', $this->valid(['default_start_time' => '13:30', 'default_end_time' => '08:30']))->assertStatus(422)
            ->assertJsonValidationErrors('default_end_time');
        $this->postJson('/api/edu-programs', $this->valid(['default_start_time' => '8.30']))->assertStatus(422)
            ->assertJsonValidationErrors('default_start_time');
        $this->postJson('/api/edu-programs', $this->valid(['title' => str_repeat('а', 2001)]))->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_index_show_update_destroy(): void
    {
        Storage::fake('local');
        $program = $this->eduProgram(['title' => 'Кариерни советници', 'staff_type' => 'career_counselors', 'school_level' => 'secondary']);
        $other = $this->eduProgram(['title' => 'Друга', 'status' => 'final']);
        $school = $this->eduSchool('СОУ „Тест“', 'Велес', ['mk'], 'secondary');
        $this->eduCounts($program, $school, 10, 0, 2);
        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk', 'start_date' => '2026-06-01', 'end_date' => '2026-06-02']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $school->id, 'language' => 'mk', 'participant_count' => 10]);

        $this->getJson('/api/edu-programs')->assertOk()->assertJsonCount(2)
            ->assertJsonStructure([['id', 'title', 'school_level', 'staff_type', 'group_size_min', 'group_size_max', 'status', 'period_label', 'groups_count', 'participants_total', 'start_date', 'end_date']]);
        $this->getJson('/api/edu-programs?status=final')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $other->id);
        $this->getJson('/api/edu-programs?search=' . urlencode('Кариерни'))->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.groups_count', 1)
            ->assertJsonPath('0.participants_total', 12)
            ->assertJsonPath('0.start_date', '2026-06-01')
            ->assertJsonPath('0.end_date', '2026-06-02');
        $this->getJson('/api/edu-programs?school_level=secondary')->assertOk()->assertJsonCount(1);

        $this->getJson("/api/edu-programs/{$program->id}")->assertOk()
            ->assertJsonPath('stats.schools', 1)
            ->assertJsonPath('stats.participants_total', 12)
            ->assertJsonPath('stats.by_language', ['mk' => 10, 'sq' => 0, 'tr' => 2])
            ->assertJsonPath('stats.assigned', 10)
            ->assertJsonPath('stats.unassigned', 2)
            ->assertJsonPath('stats.groups', 1)
            ->assertJsonPath('stats.groups_without_trainer', 1)
            ->assertJsonPath('stats.groups_without_dates', 0)
            ->assertJsonPath('stats.warnings_count', 1); // 10 < min 11

        // Update: partial, pair rules against stored values, level locked once schools exist.
        $this->putJson("/api/edu-programs/{$program->id}", ['group_size_max' => 30, 'status' => 'final'])->assertOk()
            ->assertJsonPath('group_size_max', 30)->assertJsonPath('group_size_min', 11)->assertJsonPath('status', 'final');
        $this->putJson("/api/edu-programs/{$program->id}", ['group_size_max' => 5])->assertStatus(422)->assertJsonValidationErrors('group_size_max');
        $this->putJson("/api/edu-programs/{$program->id}", ['school_level' => 'primary'])->assertStatus(422)->assertJsonValidationErrors('school_level');
        $this->putJson("/api/edu-programs/{$other->id}", ['school_level' => 'secondary'])->assertOk()->assertJsonPath('school_level', 'secondary');
        $this->putJson("/api/edu-programs/{$program->id}", ['language_map' => ['tr' => 'tr']])->assertOk()
            ->assertJsonPath('language_map', ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'tr']);

        // Delete: everything of the program goes, its imports (no FK) and their files too.
        Storage::disk('local')->put('edu-imports/x.xlsx', 'x');
        EduImport::create(['program_id' => $program->id, 'type' => 'participants', 'status' => 'previewed', 'path' => 'edu-imports/x.xlsx']);
        $this->deleteJson("/api/edu-programs/{$program->id}")->assertStatus(204);
        $this->assertNull(EduProgram::find($program->id));
        $this->assertSame(0, EduImport::where('program_id', $program->id)->count());
        Storage::disk('local')->assertMissing('edu-imports/x.xlsx');
        $this->assertSame(0, EduGroup::where('program_id', $program->id)->count());
        $this->assertSame(0, EduProgramSchool::where('program_id', $program->id)->count());
        $this->assertSame(0, EduProgramZone::where('program_id', $program->id)->count());
        $this->assertNotNull(EduSchool::find($school->id));
        $this->getJson("/api/edu-programs/{$program->id}")->assertStatus(404);
    }

    public function test_eshop_role_gets_403(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Е', 'email' => 'eshop@example.com', 'password' => 'secret', 'role' => 'eshop']));
        $this->getJson('/api/edu-programs')->assertStatus(403);
        $this->postJson('/api/edu-programs', $this->valid())->assertStatus(403);
    }

    public function test_program_schools_bulk_upsert_and_counts(): void
    {
        $program = $this->eduProgram();
        $a = $this->eduSchool('ООУ „Прво“', 'Велес');
        $b = $this->eduSchool('ООУ „Второ“', 'Кавадарци', ['mk', 'tr']);
        $secondary = $this->eduSchool('СОУ „Средно“', 'Велес', ['mk'], 'secondary');

        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [
            ['school_id' => $a->id, 'count_mk' => 5, 'count_tr' => 1],
            ['school_id' => $b->id, 'count_mk' => 3, 'comment' => 'поканети'],
        ]])->assertOk()->assertJson(['created' => 2, 'updated' => 0, 'unchanged' => 0]);

        $row = EduProgramSchool::where('school_id', $a->id)->first();
        $this->assertSame(6, $row->count_total);
        $this->assertSame('manual', $row->source);

        // Partial update keeps the other counts; the total follows.
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [
            ['school_id' => $a->id, 'count_sq' => 2],
            ['school_id' => $b->id, 'count_mk' => 3],
        ]])->assertOk()->assertJson(['created' => 0, 'updated' => 1, 'unchanged' => 1]);
        $this->assertSame([5, 2, 1, 8], array_values($row->fresh()->only(['count_mk', 'count_sq', 'count_tr', 'count_total'])));

        // Level and existence are checked for the whole batch.
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => $secondary->id, 'count_mk' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => 999999, 'count_mk' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => $a->id, 'count_mk' => -1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.count_mk');

        // Allocated participants cannot disappear.
        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $a->id, 'language' => 'mk', 'participant_count' => 4]);
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => $a->id, 'count_mk' => 3]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->getJson("/api/edu-programs/{$program->id}/schools")->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.school_id', $a->id) // Велес before Кавадарци
            ->assertJsonPath('0.allocated', ['mk' => 4, 'sq' => 0, 'tr' => 0, 'total' => 4])
            ->assertJsonPath('0.remaining', ['mk' => 1, 'sq' => 2, 'tr' => 1, 'total' => 4])
            ->assertJsonPath('1.comment', 'поканети')
            ->assertJsonStructure([['id', 'school_id', 'school' => ['id', 'display_name', 'municipality' => ['id', 'name', 'region_id']], 'count_mk', 'count_sq', 'count_tr', 'count_total', 'allocated', 'remaining', 'comment', 'source']]);
        $veles = $this->eduMunicipality('Велес');
        $this->getJson("/api/edu-programs/{$program->id}/schools?region_id={$veles->region_id}&search=" . urlencode('Прво'))->assertOk()->assertJsonCount(1);
        $zone = EduProgramZoneMunicipality::where('program_id', $program->id)->where('municipality_id', $veles->id)->value('zone_id');
        $this->getJson("/api/edu-programs/{$program->id}/schools?zone_id={$zone}")->assertOk()->assertJsonCount(2);

        // Delete: 409 while allocated, then 204.
        $this->deleteJson("/api/edu-programs/{$program->id}/schools/{$a->id}")->assertStatus(409);
        $this->deleteJson("/api/edu-programs/{$program->id}/schools/{$b->id}")->assertStatus(204);
        $this->deleteJson("/api/edu-programs/{$program->id}/schools/{$b->id}")->assertStatus(404);

        // Persons mode: counts come from participants.
        $program->update(['participant_mode' => 'persons']);
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => $a->id, 'count_mk' => 9]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->putJson("/api/edu-programs/{$program->id}/schools", ['items' => [['school_id' => $a->id, 'comment' => 'ок']]])->assertOk();
    }

    public function test_school_delete_is_blocked_by_programs_and_show_lists_them(): void
    {
        $program = $this->eduProgram(['title' => 'Програма А']);
        $school = $this->eduSchool('ООУ „Користено“', 'Велес');
        $free = $this->eduSchool('ООУ „Слободно“', 'Велес');
        $this->eduCounts($program, $school, 4, 0, 1);

        $this->getJson("/api/edu-schools/{$school->id}")->assertOk()
            ->assertJsonPath('programs', [[
                'program_id' => $program->id, 'title' => 'Програма А', 'status' => 'draft', 'period_label' => null,
                'count_mk' => 4, 'count_sq' => 0, 'count_tr' => 1, 'count_total' => 5,
            ]]);
        $this->deleteJson("/api/edu-schools/{$school->id}")->assertStatus(409)->assertJsonPath('program_ids', [$program->id]);
        $this->deleteJson("/api/edu-schools/{$free->id}")->assertStatus(204);
    }

    public function test_location_delete_keeps_the_name_on_groups(): void
    {
        $program = $this->eduProgram();
        $location = EduLocation::create(['name' => 'Хотел Тест', 'kind' => 'hotel', 'municipality_id' => $this->eduMunicipality('Велес')->id]);
        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk', 'location_id' => $location->id]);

        $this->deleteJson("/api/edu-locations/{$location->id}")->assertStatus(204);
        $this->assertSame('Хотел Тест', $group->fresh()->location_text);
        $this->assertNull($group->fresh()->location_id);
    }

    public function test_participants_crud_and_move(): void
    {
        $program = $this->eduProgram();
        $a = $this->eduSchool('ООУ „Прво“', 'Велес');
        $b = $this->eduSchool('ООУ „Второ“', 'Велес', ['mk', 'sq']);
        $secondary = $this->eduSchool('СОУ „Средно“', 'Велес', ['mk'], 'secondary');

        // The first participant switches an empty program to the persons mode.
        $p1 = $this->postJson("/api/edu-programs/{$program->id}/participants", [
            'full_name' => '  Ана   Тестовска ', 'school_id' => $a->id, 'language' => 'mk', 'email' => 'ANA@Test.mk',
        ])->assertStatus(201)
            ->assertJsonPath('full_name', 'Ана Тестовска')
            ->assertJsonPath('email', 'ana@test.mk')
            ->assertJsonPath('municipality_id', $a->municipality_id)
            ->assertJsonPath('school.display_name', 'ООУ „Прво“')
            ->json('id');
        $this->assertSame('persons', $program->fresh()->participant_mode);
        $this->assertSame(1, EduProgramSchool::where('program_id', $program->id)->where('school_id', $a->id)->value('count_mk'));
        $this->assertSame('persons', EduProgramSchool::where('school_id', $a->id)->value('source'));

        $this->postJson("/api/edu-programs/{$program->id}/participants", ['full_name' => 'X', 'school_id' => $secondary->id, 'language' => 'mk'])
            ->assertStatus(422)->assertJsonValidationErrors('school_id');
        $this->postJson("/api/edu-programs/{$program->id}/participants", ['full_name' => 'X', 'school_id' => $a->id, 'language' => 'de'])
            ->assertStatus(422)->assertJsonValidationErrors('language');
        $p2 = $this->postJson("/api/edu-programs/{$program->id}/participants", ['full_name' => 'Бесник Тест', 'school_id' => $b->id, 'language' => 'sq'])
            ->assertStatus(201)->json('id');

        // Update: a new school moves the count.
        $this->putJson("/api/edu-participants/{$p1}", ['school_id' => $b->id])->assertOk()->assertJsonPath('school_id', $b->id);
        $this->assertSame(0, EduProgramSchool::where('school_id', $a->id)->value('count_total'));
        $this->assertSame([1, 1], array_values(EduProgramSchool::where('school_id', $b->id)->first()->only(['count_mk', 'count_sq'])));

        // Move into a group: the allocations follow the people.
        $g1 = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk']);
        $g2 = EduGroup::create(['program_id' => $program->id, 'number' => 2, 'language' => 'sq']);
        $this->postJson('/api/edu-participants/move', ['participant_ids' => [$p1, $p2], 'group_id' => $g1->id])->assertOk()
            ->assertJsonPath('moved', 2)
            ->assertJsonPath('groups.0.id', $g1->id)
            ->assertJsonPath('groups.0.total', 2);
        $this->assertSame(2, (int) EduGroupSchool::where('group_id', $g1->id)->sum('participant_count'));
        $this->postJson('/api/edu-participants/move', ['participant_ids' => [$p2], 'group_id' => $g2->id])->assertOk()
            ->assertJsonCount(2, 'groups');
        $this->assertSame(1, (int) EduGroupSchool::where('group_id', $g1->id)->sum('participant_count'));
        $this->assertSame(['sq'], EduGroupSchool::where('group_id', $g2->id)->pluck('language')->all());

        // Back to unassigned.
        $this->postJson('/api/edu-participants/move', ['participant_ids' => [$p2], 'group_id' => null])->assertOk();
        $this->assertSame(0, EduGroupSchool::where('group_id', $g2->id)->count());

        // Another program's group is refused.
        $other = $this->eduProgram();
        $foreign = EduGroup::create(['program_id' => $other->id, 'number' => 1, 'language' => 'mk']);
        $this->postJson('/api/edu-participants/move', ['participant_ids' => [$p1], 'group_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('group_id');

        // Index with filters.
        $this->getJson("/api/edu-programs/{$program->id}/participants")->assertOk()->assertJsonPath('total', 2)
            ->assertJsonStructure(['data' => [['id', 'full_name', 'school_id', 'language', 'email', 'group_id', 'school' => ['id', 'display_name'], 'group']], 'current_page', 'per_page', 'total']);
        $this->getJson("/api/edu-programs/{$program->id}/participants?unassigned=1")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $p2);
        $this->getJson("/api/edu-programs/{$program->id}/participants?group_id={$g1->id}")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/edu-programs/{$program->id}/participants?language=sq&search=" . urlencode('бесник'))->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/edu-programs/{$program->id}/participants?per_page=1000")->assertOk()->assertJsonPath('per_page', 100);

        // Delete: counts and allocations follow.
        $this->deleteJson("/api/edu-participants/{$p1}")->assertStatus(204);
        $this->assertSame(0, EduGroupSchool::where('group_id', $g1->id)->count());
        $this->assertSame(0, EduProgramSchool::where('school_id', $b->id)->value('count_mk'));
        $this->assertSame(1, EduParticipant::where('program_id', $program->id)->count());
    }

    public function test_adding_a_person_to_a_program_with_counts_is_refused(): void
    {
        $program = $this->eduProgram();
        $school = $this->eduSchool('ООУ „Прво“', 'Велес');
        $this->eduCounts($program, $school, 5);

        $this->postJson("/api/edu-programs/{$program->id}/participants", ['full_name' => 'Ана', 'school_id' => $school->id, 'language' => 'mk'])
            ->assertStatus(409);
        $this->assertSame('counts', $program->fresh()->participant_mode);
    }

    public function test_switching_to_persons_fills_existing_groups(): void
    {
        $program = $this->eduProgram();
        $school = $this->eduSchool('ООУ „Прво“', 'Велес');
        $this->eduCounts($program, $school, 3);
        // Without any people the counts would be lost.
        $this->putJson("/api/edu-programs/{$program->id}", ['participant_mode' => 'persons'])->assertStatus(422)->assertJsonValidationErrors('participant_mode');

        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $school->id, 'language' => 'mk', 'participant_count' => 3]);
        foreach (['Еден', 'Двајца'] as $name) {
            EduParticipant::create(['program_id' => $program->id, 'school_id' => $school->id, 'language' => 'mk', 'full_name' => $name, 'name_key' => $name]);
        }

        $this->putJson("/api/edu-programs/{$program->id}", ['participant_mode' => 'persons'])->assertOk()
            ->assertJsonPath('participant_mode', 'persons')
            ->assertJsonPath('stats.participants_total', 2)
            ->assertJsonPath('stats.assigned', 2);
        $this->assertSame(2, EduParticipant::where('group_id', $group->id)->count());
        $this->assertSame(2, EduGroupSchool::where('group_id', $group->id)->value('participant_count'));

        $this->putJson("/api/edu-programs/{$program->id}", ['participant_mode' => 'counts'])->assertOk();
        $this->assertSame('manual', EduProgramSchool::where('program_id', $program->id)->value('source'));
    }
}
