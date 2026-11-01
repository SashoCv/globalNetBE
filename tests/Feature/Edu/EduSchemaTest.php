<?php

namespace Tests\Feature\Edu;

use App\Models\EduLocation;
use App\Models\EduMunicipality;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use App\Models\EduTrainerHub;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EduSchemaTest extends TestCase
{
    use RefreshDatabase;

    private EduRegion $region;
    private EduMunicipality $hub;
    private EduMunicipality $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = EduRegion::create(['name' => 'Тест регион', 'sort_order' => 1]);
        $this->hub = EduMunicipality::create(['name' => 'Центар Тест', 'region_id' => $this->region->id, 'is_training_hub' => true]);
        $this->hub->update(['hub_municipality_id' => $this->hub->id]);
        $this->town = EduMunicipality::create(['name' => 'Село Тест', 'region_id' => $this->region->id, 'hub_municipality_id' => $this->hub->id]);
    }

    private function school(string $name, array $languages = []): EduSchool
    {
        return EduSchool::create(EduSchool::nameFields($name, $this->town->name) + [
            'level' => EduSchool::LEVEL_PRIMARY,
            'municipality_id' => $this->town->id,
            'languages' => $languages,
        ]);
    }

    public function test_relations(): void
    {
        $school = $this->school('ООУ „Тест“ с.Тестово', ['mk']);
        $trainer = EduTrainer::create(['full_name' => 'Тест Тестовски', 'name_key' => 'тест тестовски', 'home_municipality_id' => $this->town->id]);
        $topic = EduTopic::create(['title' => 'Тема']);
        $trainer->topics()->attach($topic);
        EduTrainerHub::create(['trainer_id' => $trainer->id, 'hub_municipality_id' => $this->hub->id]);
        $location = EduLocation::create(['name' => 'Сала', 'kind' => 'school', 'school_id' => $school->id, 'municipality_id' => $this->town->id]);

        $this->assertSame($this->hub->id, $this->town->hub->id);
        $this->assertSame($this->region->id, $this->town->region->id);
        $this->assertTrue($this->hub->served->contains($this->town));
        $this->assertSame('Тестово', $school->settlement);
        $this->assertSame([], EduSchool::find($this->school('ООУ „Друго“')->id)->languages);
        $this->assertTrue($trainer->topics->contains($topic));
        $this->assertTrue($topic->trainers->contains($trainer));
        $this->assertSame($this->hub->id, $trainer->hubs->first()->hub_municipality_id);
        $this->assertSame($this->town->id, $trainer->homeMunicipality->id);
        $this->assertSame($school->id, $location->school->id);
        $this->assertSame([], $trainer->fresh()->languages);
    }

    public function test_deleting_a_trainer_cascades_and_a_school_nulls_its_locations(): void
    {
        $school = $this->school('ООУ „Тест“');
        $trainer = EduTrainer::create(['full_name' => 'Тест', 'name_key' => 'тест']);
        $topic = EduTopic::create(['title' => 'Тема']);
        $trainer->topics()->attach($topic);
        EduTrainerHub::create(['trainer_id' => $trainer->id, 'hub_municipality_id' => $this->hub->id, 'topic_id' => $topic->id]);
        $location = EduLocation::create(['name' => 'Сала', 'kind' => 'school', 'school_id' => $school->id, 'municipality_id' => $this->town->id]);

        $trainer->delete();
        $school->delete();

        $this->assertSame(0, EduTrainerHub::count());
        $this->assertSame(0, DB::table('edu_topic_trainers')->count());
        $this->assertNull($location->fresh()->school_id);
    }

    public function test_a_municipality_with_schools_cannot_be_deleted(): void
    {
        $this->school('ООУ „Тест“');

        $this->expectException(QueryException::class);
        $this->town->delete();
    }

    public function test_a_region_with_municipalities_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);
        $this->region->delete();
    }

    public function test_the_natural_key_is_unique_per_level_and_municipality(): void
    {
        $this->school('ООУ „Тест“');

        $this->expectException(QueryException::class);
        $this->school('ОУ „Тест“');
    }

    public function test_schools_are_found_by_language(): void
    {
        $this->school('ООУ „Македонско“', ['mk']);
        $this->school('ООУ „Двојазично“', ['mk', 'sq']);
        $this->school('ООУ „Непознато“');

        $this->assertSame(2, EduSchool::whereJsonContains('languages', 'mk')->count());
        $this->assertSame(1, EduSchool::whereJsonContains('languages', 'sq')->count());
        $this->assertSame(1, EduSchool::whereJsonLength('languages', 0)->count());
    }
}
