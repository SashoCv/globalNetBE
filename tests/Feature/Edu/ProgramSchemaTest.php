<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduGroupSession;
use App\Models\EduParticipant;
use App\Models\EduProgramSchool;
use App\Models\EduTrainer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class ProgramSchemaTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp(false);
    }

    public function test_casts_totals_and_keys(): void
    {
        $program = $this->eduProgram(['default_start_time' => '08:30']);
        $school = $this->eduSchool('ООУ „Тест“', 'Велес');
        $row = $this->eduCounts($program, $school, 3, 2, 1);
        $this->assertSame(6, $row->fresh()->count_total);
        $row->update(['count_sq' => 0]);
        $this->assertSame(4, $row->fresh()->count_total);
        $this->assertSame('08:30', $program->fresh()->default_start_time);
        $this->assertSame(['mk' => 'mk', 'sq' => 'sq', 'tr' => 'mk'], $program->language_map);

        $trainer = EduTrainer::create(['full_name' => 'Обучувач', 'name_key' => 'обучувач']);
        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk', 'trainer_id' => $trainer->id, 'start_date' => '2026-06-01']);
        $session = EduGroupSession::create(['group_id' => $group->id, 'date' => '2026-06-01', 'start_time' => '08:30', 'end_time' => '13:30']);
        $this->assertSame('2026-06-01', $group->fresh()->toArray()['start_date']);
        $this->assertSame('2026-06-01', $session->fresh()->toArray()['date']);
        $this->assertSame('08:30', $session->fresh()->start_time);
        $this->assertSame($group->id, $program->groups()->first()->id);

        // Trainer deleted → the group stays without one.
        $trainer->delete();
        $this->assertNull($group->fresh()->trainer_id);

        // Unique group number per program, unique session day per group.
        try {
            EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'sq']);
            $this->fail('duplicate group number');
        } catch (QueryException) {
        }
        $this->expectException(QueryException::class);
        EduGroupSession::create(['group_id' => $group->id, 'date' => '2026-06-01', 'start_time' => '08:30', 'end_time' => '13:30']);
    }

    public function test_schools_in_programs_cannot_be_deleted_and_groups_cascade(): void
    {
        $program = $this->eduProgram();
        $school = $this->eduSchool('ООУ „Тест“', 'Велес');
        $this->eduCounts($program, $school, 3);
        $group = EduGroup::create(['program_id' => $program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSession::create(['group_id' => $group->id, 'date' => '2026-06-01', 'start_time' => '08:30', 'end_time' => '13:30']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $school->id, 'language' => 'mk', 'participant_count' => 3]);
        $person = EduParticipant::create(['program_id' => $program->id, 'school_id' => $school->id, 'language' => 'mk', 'full_name' => 'Ана', 'name_key' => 'ана', 'group_id' => $group->id]);

        try {
            $school->delete();
            $this->fail('school in a program was deleted');
        } catch (QueryException) {
        }

        $group->delete();
        $this->assertSame(0, EduGroupSession::count());
        $this->assertSame(0, EduGroupSchool::count());
        $this->assertNull($person->fresh()->group_id);

        $program->delete();
        $this->assertSame(0, EduParticipant::count());
        $this->assertSame(0, EduProgramSchool::count());
    }
}
