<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduProgram;
use App\Models\EduProgramZone;
use App\Models\EduProgramZoneMunicipality as Member;
use App\Services\Edu\ProgramZoneService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class ZoneServiceTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    private EduProgram $program;
    private ProgramZoneService $zones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp();
        $this->program = $this->eduProgram();
        $this->zones = app(ProgramZoneService::class);
    }

    private function zone(string $name): EduProgramZone
    {
        return EduProgramZone::where('program_id', $this->program->id)->where('name', $name)->firstOrFail();
    }

    private function zoneOf(string $municipality, string $language, ?string $groupLanguage = null): ?int
    {
        return ProgramZoneService::lookup($this->zones->map($this->program), $this->eduMunicipality($municipality)->id, $language, $groupLanguage);
    }

    public function test_one_row_per_municipality_and_language(): void
    {
        $dolneni = $this->eduMunicipality('Долнени');
        $this->expectException(QueryException::class);
        Member::create(['program_id' => $this->program->id, 'zone_id' => $this->zone('Југозападен')->id, 'municipality_id' => $dolneni->id, 'language' => '*']);
    }

    public function test_a_language_exception_wins_over_the_default(): void
    {
        $pelagonia = $this->zone('Пелагониски');
        $southwest = $this->zone('Југозападен');
        $this->assertSame($pelagonia->id, $this->zoneOf('Долнени', 'sq'));

        // Albanian Долнени → the Кичево zone; Macedonian stays.
        $this->assertSame(['from_zone_id' => $pelagonia->id, 'to_zone_id' => $southwest->id], $this->zones->assign($this->program, $this->eduMunicipality('Долнени')->id, $southwest, 'sq'));
        $this->assertSame($southwest->id, $this->zoneOf('Долнени', 'sq'));
        $this->assertSame($pelagonia->id, $this->zoneOf('Долнени', 'mk'));
        // Turkish participants grouped in Macedonian follow the "mk" exception.
        $this->zones->assign($this->program, $this->eduMunicipality('Долнени')->id, $this->zone('Вардарски'), 'mk');
        $this->assertSame($this->zone('Вардарски')->id, $this->zoneOf('Долнени', 'tr', 'mk'));

        // Moving the exception back to the default zone removes it.
        $this->zones->assign($this->program, $this->eduMunicipality('Долнени')->id, $pelagonia, 'sq');
        $this->assertSame(0, Member::where('program_id', $this->program->id)->where('municipality_id', $this->eduMunicipality('Долнени')->id)->where('language', 'sq')->count());

        // Moving the default for all languages drops exceptions that now say the same.
        $this->zones->assign($this->program, $this->eduMunicipality('Долнени')->id, $this->zone('Вардарски'));
        $this->assertSame(['*'], Member::where('program_id', $this->program->id)->where('municipality_id', $this->eduMunicipality('Долнени')->id)->pluck('language')->all());

        // Other programs are not affected.
        $other = $this->eduProgram();
        $this->assertSame(
            EduProgramZone::where('program_id', $other->id)->where('name', 'Пелагониски')->value('id'),
            ProgramZoneService::lookup($this->zones->map($other), $this->eduMunicipality('Долнени')->id, 'sq'),
        );
    }

    public function test_merge_moves_municipalities_and_groups(): void
    {
        $east = $this->zone('Источен');
        $southeast = $this->zone('Југоисточен');
        $group = EduGroup::create(['program_id' => $this->program->id, 'number' => 1, 'language' => 'mk', 'zone_id' => $east->id]);
        $before = Member::where('zone_id', $east->id)->count() + Member::where('zone_id', $southeast->id)->count();

        $this->zones->merge($east, $southeast);

        $this->assertNull(EduProgramZone::find($east->id));
        $this->assertSame($before, Member::where('zone_id', $southeast->id)->count());
        $this->assertSame($southeast->id, $group->fresh()->zone_id);
        $this->assertSame($southeast->id, $this->zoneOf('Кочани', 'mk'));
    }

    public function test_reset_restores_the_regions(): void
    {
        $southwest = $this->zone('Југозападен');
        $new = $this->zones->store($this->program, 'Кичево и Долнени', [$this->eduMunicipality('Долнени')->id], 'sq');
        $group = EduGroup::create(['program_id' => $this->program->id, 'number' => 1, 'language' => 'sq', 'zone_id' => $southwest->id]);
        $orphan = EduGroup::create(['program_id' => $this->program->id, 'number' => 2, 'language' => 'sq', 'zone_id' => $new->id]);
        $this->zones->merge($this->zone('Пелагониски'), $southwest);

        $this->zones->reset($this->program);

        $this->assertSame(8, EduProgramZone::where('program_id', $this->program->id)->count());
        $this->assertSame(81, Member::where('program_id', $this->program->id)->count());
        $this->assertSame($this->zone('Пелагониски')->id, $this->zoneOf('Долнени', 'sq'));
        $this->assertSame($this->zone('Југозападен')->id, $group->fresh()->zone_id);
        $this->assertNull($orphan->fresh()->zone_id);
    }

    public function test_zones_api(): void
    {
        $id = $this->program->id;
        $veles = $this->eduSchool('ООУ „Велешко“', 'Велес', ['mk', 'tr']);
        $dolneni = $this->eduSchool('ООУ „Долненско“', 'Долнени', ['mk', 'sq']);
        $this->eduCounts($this->program, $veles, 10, 0, 2);
        $this->eduCounts($this->program, $dolneni, 4, 6);
        $vardar = $this->zone('Вардарски');
        $southwest = $this->zone('Југозападен');

        $this->putJson("/api/edu-programs/{$id}/zones/assign", ['municipality_id' => $this->eduMunicipality('Долнени')->id, 'zone_id' => $southwest->id, 'language' => 'sq'])
            ->assertOk()->assertJson(['from_zone_id' => $this->zone('Пелагониски')->id, 'to_zone_id' => $southwest->id]);

        $zones = collect($this->getJson("/api/edu-programs/{$id}/zones")->assertOk()
            ->assertJsonCount(8)
            ->assertJsonStructure([['id', 'name', 'region_id', 'sort_order', 'municipalities' => [['id', 'name', 'language', 'demand' => ['mk', 'sq', 'tr']]], 'totals']])
            ->json())->keyBy('name');
        $this->assertSame(['mk' => 10, 'sq' => 0, 'tr' => 2, 'total' => 12], $zones['Вардарски']['totals']);
        $this->assertSame(['mk' => 4, 'sq' => 0, 'tr' => 0, 'total' => 4], $zones['Пелагониски']['totals']);
        $this->assertSame(['mk' => 0, 'sq' => 6, 'tr' => 0, 'total' => 6], $zones['Југозападен']['totals']);
        $exception = collect($zones['Југозападен']['municipalities'])->firstWhere('language', 'sq');
        $this->assertSame('Долнени', $exception['name']);

        $this->putJson("/api/edu-programs/{$id}/zones/assign", ['municipality_id' => $this->eduMunicipality('Велес')->id, 'zone_id' => 999999])->assertStatus(422);
        $this->putJson("/api/edu-programs/{$id}/zones/assign", ['municipality_id' => $this->eduMunicipality('Велес')->id, 'zone_id' => $vardar->id, 'language' => 'de'])->assertStatus(422);

        // New zone with a municipality, rename, merge, delete.
        $created = $this->postJson("/api/edu-programs/{$id}/zones", ['name' => 'Велес и околина', 'municipality_ids' => [$this->eduMunicipality('Велес')->id]])
            ->assertStatus(201)->assertJsonPath('name', 'Велес и околина')->assertJsonPath('totals.total', 12)->json('id');
        $this->putJson("/api/edu-zones/{$created}", ['name' => 'Велес'])->assertOk()->assertJsonPath('name', 'Велес');
        $this->deleteJson("/api/edu-zones/{$created}")->assertStatus(409);
        $this->postJson("/api/edu-zones/{$created}/merge", ['into_zone_id' => $created])->assertStatus(422);
        $foreign = EduProgramZone::where('program_id', $this->eduProgram()->id)->value('id');
        $this->postJson("/api/edu-zones/{$created}/merge", ['into_zone_id' => $foreign])->assertStatus(422);
        $this->postJson("/api/edu-zones/{$created}/merge", ['into_zone_id' => $vardar->id])->assertOk()
            ->assertJsonPath('id', $vardar->id)->assertJsonPath('totals.total', 12);
        $empty = $this->postJson("/api/edu-programs/{$id}/zones", ['name' => 'Празна'])->assertStatus(201)->json('id');
        $this->deleteJson("/api/edu-zones/{$empty}")->assertStatus(204);

        $this->postJson("/api/edu-programs/{$id}/zones/reset", [])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson("/api/edu-programs/{$id}/zones/reset", ['confirm' => true])->assertOk()->assertJsonCount(8);
    }
}
