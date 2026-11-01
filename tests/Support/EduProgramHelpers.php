<?php

namespace Tests\Support;

use App\Models\EduMunicipality;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduSchool;
use App\Models\User;
use App\Services\Edu\ProgramZoneService;
use Database\Seeders\EduGeoSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Builds Обуки programs for tests on top of the seeded geography
 * (EduGeoSeeder): schools, programs with their zones, per-school counts.
 */
trait EduProgramHelpers
{
    protected function eduSetUp(bool $actingAsAdmin = true): void
    {
        $this->seed(EduGeoSeeder::class);
        if ($actingAsAdmin) {
            Sanctum::actingAs(User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret', 'role' => 'admin']));
        }
    }

    protected function eduMunicipality(string $name): EduMunicipality
    {
        return EduMunicipality::where('name', $name)->firstOrFail();
    }

    protected function eduSchool(string $name, string $municipality, array $languages = ['mk'], string $level = EduSchool::LEVEL_PRIMARY): EduSchool
    {
        $m = $this->eduMunicipality($municipality);

        return EduSchool::create(EduSchool::nameFields($name, $m->name) + [
            'level' => $level,
            'municipality_id' => $m->id,
            'languages' => $languages,
        ]);
    }

    protected function eduProgram(array $attributes = []): EduProgram
    {
        $program = EduProgram::create($attributes + [
            'title' => 'Тест програма',
            'school_level' => EduSchool::LEVEL_PRIMARY,
            'staff_type' => 'teachers',
            'group_size_min' => 11,
            'group_size_max' => 25,
        ]);
        app(ProgramZoneService::class)->initFromRegions($program);

        return $program->fresh();
    }

    protected function eduCounts(EduProgram $program, EduSchool $school, int $mk = 0, int $sq = 0, int $tr = 0): EduProgramSchool
    {
        return EduProgramSchool::updateOrCreate(
            ['program_id' => $program->id, 'school_id' => $school->id],
            ['count_mk' => $mk, 'count_sq' => $sq, 'count_tr' => $tr, 'source' => 'manual'],
        );
    }
}
