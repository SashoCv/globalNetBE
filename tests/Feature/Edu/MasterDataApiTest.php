<?php

namespace Tests\Feature\Edu;

use App\Models\EduLocation;
use App\Models\EduMunicipality;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use App\Models\User;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EduGeoSeeder::class);
        Sanctum::actingAs(User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret', 'role' => 'admin']));
    }

    private function municipality(string $name): EduMunicipality
    {
        return EduMunicipality::where('name', $name)->firstOrFail();
    }

    private function school(string $name, string $municipality, string $level = 'primary', array $languages = []): EduSchool
    {
        $m = $this->municipality($municipality);

        return EduSchool::create(EduSchool::nameFields($name, $m->name) + ['level' => $level, 'municipality_id' => $m->id, 'languages' => $languages]);
    }

    public function test_the_eshop_role_gets_403_on_edu_routes(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Е-продавница', 'email' => 'eshop@example.com', 'password' => 'secret', 'role' => 'eshop']));

        $this->getJson('/api/edu-schools')->assertStatus(403);
        $this->getJson('/api/edu-lookups')->assertStatus(403);
        $this->postJson('/api/edu-trainers', ['full_name' => 'X'])->assertStatus(403);
        $this->postJson('/api/edu-imports/workbook')->assertStatus(403);
    }

    public function test_guests_get_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/edu-schools')->assertStatus(401);
    }

    public function test_lookups(): void
    {
        EduTopic::create(['title' => 'Тема']);

        $response = $this->getJson('/api/edu-lookups')->assertOk();

        $response->assertJsonCount(8, 'regions')
            ->assertJsonCount(81, 'municipalities')
            ->assertJsonCount(20, 'hubs')
            ->assertJsonCount(1, 'topics')
            ->assertJsonCount(3, 'languages')
            ->assertJsonPath('languages.0', ['code' => 'mk', 'label' => 'мк', 'name' => 'Македонски'])
            ->assertJsonPath('enums.school_levels.0.value', 'primary')
            ->assertJsonStructure(['municipalities' => [['id', 'name', 'region_id', 'is_training_hub', 'hub_municipality_id', 'part_of_skopje']], 'hubs' => [['id', 'name', 'hub_short_name']]]);
    }

    public function test_regions_crud_and_conflicts(): void
    {
        $this->getJson('/api/edu-regions')->assertOk()->assertJsonCount(8)
            ->assertJsonStructure([['id', 'name', 'sort_order', 'municipalities_count', 'municipalities' => [['id', 'name']]]]);

        $id = $this->postJson('/api/edu-regions', ['name' => 'Нов регион'])->assertStatus(201)->json('id');
        $this->postJson('/api/edu-regions', ['name' => 'Нов регион'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson("/api/edu-regions/{$id}", ['name' => 'Преименуван'])->assertOk()->assertJsonPath('name', 'Преименуван');
        $this->deleteJson("/api/edu-regions/{$id}")->assertStatus(204);

        $skopski = EduRegion::where('name', 'Скопски')->first();
        $this->deleteJson("/api/edu-regions/{$skopski->id}")->assertStatus(409)->assertJsonStructure(['message']);
    }

    public function test_municipalities_crud_filters_and_conflicts(): void
    {
        $skopski = EduRegion::where('name', 'Скопски')->first();
        $this->getJson("/api/edu-municipalities?region_id={$skopski->id}")->assertOk()->assertJsonCount(18);
        $this->getJson('/api/edu-municipalities?hubs_only=1')->assertOk()->assertJsonCount(20);
        $this->getJson('/api/edu-municipalities?search=Tetov')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Тетово')->assertJsonPath('0.region.name', 'Полошки')->assertJsonPath('0.hub.name', 'Тетово');
        // Aliases are stored unescaped, so Cyrillic alias search works.
        $this->getJson('/api/edu-municipalities?search=' . urlencode('Чучер-Сандево'))->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Чучер Сандево');

        $kumanovo = $this->municipality('Куманово');
        $created = $this->postJson('/api/edu-municipalities', [
            'name' => 'Ново Место', 'region_id' => $skopski->id, 'hub_municipality_id' => $kumanovo->id, 'aliases' => ['Novo Mesto', ' '],
        ])->assertStatus(201)->assertJsonPath('hub.name', 'Куманово')->assertJsonPath('aliases', ['Novo Mesto']);
        $id = $created->json('id');

        // The centre must be a centre.
        $this->postJson('/api/edu-municipalities', ['name' => 'Друго', 'region_id' => $skopski->id, 'hub_municipality_id' => $this->municipality('Липково')->id])
            ->assertStatus(422)->assertJsonValidationErrors('hub_municipality_id');
        $this->postJson('/api/edu-municipalities', ['name' => 'Тетово', 'region_id' => $skopski->id])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/edu-municipalities', ['name' => 'Без регион'])->assertStatus(422)->assertJsonValidationErrors('region_id');

        // Becoming a centre points the municipality at itself.
        $this->putJson("/api/edu-municipalities/{$id}", ['is_training_hub' => true])->assertOk()->assertJsonPath('hub_municipality_id', $id);
        $this->deleteJson("/api/edu-municipalities/{$id}")->assertStatus(204);

        // A centre serving others cannot stop being one or be deleted; nor can a municipality with schools.
        $this->putJson("/api/edu-municipalities/{$kumanovo->id}", ['is_training_hub' => false])->assertStatus(409);
        $this->deleteJson("/api/edu-municipalities/{$kumanovo->id}")->assertStatus(409);
        $this->school('ООУ „Тест“', 'Липково');
        $this->deleteJson('/api/edu-municipalities/' . $this->municipality('Липково')->id)->assertStatus(409);
    }

    public function test_school_create_update_show_delete(): void
    {
        $struga = $this->municipality('Струга');
        $payload = [
            'level' => 'primary',
            'display_name' => 'ООУ „Гоце Делчев“ с.Јабланица',
            'municipality_id' => $struga->id,
            'languages' => ['sq', 'mk'],
            'emails' => ['Test@Example.com'],
            'phones' => ['070123456'],
        ];

        $id = $this->postJson('/api/edu-schools', $payload)->assertStatus(201)
            ->assertJsonPath('name_key', 'гоце делчев с јабланица')
            ->assertJsonPath('settlement', 'Јабланица')
            ->assertJsonPath('languages', ['mk', 'sq'])
            ->assertJsonPath('emails', ['test@example.com'])
            ->assertJsonPath('municipality.name', 'Струга')
            ->json('id');

        // Another village is another school; the same village is a duplicate.
        $this->postJson('/api/edu-schools', array_merge($payload, ['display_name' => 'ООУ „Гоце Делчев“ с.Подгорци']))->assertStatus(201);
        $this->postJson('/api/edu-schools', array_merge($payload, ['display_name' => 'ОУ Гоце Делчев с.Јабланица']))
            ->assertStatus(422)->assertJsonValidationErrors('display_name');

        $this->postJson('/api/edu-schools', ['level' => 'high', 'display_name' => '', 'municipality_id' => 99999, 'languages' => ['en'], 'emails' => ['nope']])
            ->assertStatus(422)->assertJsonValidationErrors(['level', 'display_name', 'municipality_id', 'languages.0', 'emails.0']);

        $this->putJson("/api/edu-schools/{$id}", ['display_name' => 'ООУ „Гоце Делчев“ с.Ливада', 'director_name' => 'Директор'])
            ->assertOk()->assertJsonPath('name_key', 'гоце делчев с ливада')->assertJsonPath('director_name', 'Директор');
        $this->putJson("/api/edu-schools/{$id}", ['languages' => []])->assertOk()->assertJsonPath('languages', []);

        $this->getJson("/api/edu-schools/{$id}")->assertOk()
            ->assertJsonPath('municipality.region.name', 'Југозападен')
            ->assertJsonPath('programs', []);

        $this->deleteJson("/api/edu-schools/{$id}")->assertStatus(204);
        $this->getJson("/api/edu-schools/{$id}")->assertStatus(404);
    }

    public function test_schools_differing_only_by_prefix_can_coexist(): void
    {
        $gostivar = $this->municipality('Гостивар');
        $this->postJson('/api/edu-schools', ['level' => 'secondary', 'display_name' => 'СОУ „Гостивар“', 'municipality_id' => $gostivar->id])->assertStatus(201);
        $this->postJson('/api/edu-schools', ['level' => 'secondary', 'display_name' => 'СОМУ „Гостивар“', 'municipality_id' => $gostivar->id])
            ->assertStatus(201)->assertJsonPath('name_key', 'сому гостивар');
    }

    public function test_school_list_filters_and_pagination(): void
    {
        foreach (range(1, 6) as $i) {
            $this->school("ООУ „Тест {$i}“", 'Струга', 'primary', $i % 2 ? ['sq'] : []);
        }
        $this->school('СОУ „Средно“', 'Тетово', 'secondary', ['mk', 'sq']);
        $this->school('ООУ „Скопско“', 'Карпош', 'primary', ['mk']);

        // per_page is clamped to 5..100.
        $this->getJson('/api/edu-schools?per_page=1')->assertOk()->assertJsonPath('per_page', 5)->assertJsonPath('total', 8)->assertJsonCount(5, 'data');
        $this->getJson('/api/edu-schools?per_page=1000')->assertOk()->assertJsonPath('per_page', 100);
        $this->getJson('/api/edu-schools?per_page=5&page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/edu-schools?all=1')->assertOk()->assertJsonCount(8);

        $this->getJson('/api/edu-schools?level=secondary')->assertJsonPath('total', 1)->assertJsonPath('data.0.display_name', 'СОУ „Средно“');
        $this->getJson('/api/edu-schools?language=sq')->assertJsonPath('total', 4);
        $this->getJson('/api/edu-schools?language=unknown')->assertJsonPath('total', 3);
        $this->getJson('/api/edu-schools?region_id=' . EduRegion::where('name', 'Скопски')->value('id'))->assertJsonPath('total', 1);
        $this->getJson('/api/edu-schools?municipality_id=' . $this->municipality('Струга')->id)->assertJsonPath('total', 6);
        $this->getJson('/api/edu-schools?search=' . urlencode('Тест 3'))->assertJsonPath('total', 1)->assertJsonPath('data.0.municipality.name', 'Струга');
        $this->getJson('/api/edu-schools?sort=display_name&direction=desc&all=1')->assertJsonPath('0.display_name', 'СОУ „Средно“');

        $this->getJson('/api/edu-schools/stats')->assertOk()
            ->assertJsonPath('total', 8)
            ->assertJsonPath('by_level', ['primary' => 7, 'secondary' => 1])
            ->assertJsonPath('unknown_language', 3)
            ->assertJsonCount(8, 'by_region');
        $this->getJson('/api/edu-schools/stats?level=secondary')->assertJsonPath('total', 1)->assertJsonPath('unknown_language', 0);
    }

    public function test_topics_and_their_trainers(): void
    {
        $id = $this->postJson('/api/edu-topics', ['title' => str_repeat('Долг наслов ', 40)])->assertStatus(201)->assertJsonPath('trainers_count', 0)->json('id');
        $this->postJson('/api/edu-topics', ['short_title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->putJson("/api/edu-topics/{$id}", ['short_title' => 'Кратко'])->assertOk()->assertJsonPath('short_title', 'Кратко');

        $a = EduTrainer::create(['full_name' => 'Прв Тест', 'name_key' => 'прв тест']);
        $b = EduTrainer::create(['full_name' => 'Втор Тест', 'name_key' => 'втор тест']);
        $this->putJson("/api/edu-topics/{$id}/trainers", ['trainer_ids' => [$a->id, $b->id]])->assertOk()
            ->assertJsonPath('trainers_count', 2)->assertJsonCount(2, 'trainers');
        $this->putJson("/api/edu-topics/{$id}/trainers", ['trainer_ids' => [$a->id, 99999]])->assertStatus(422);
        $this->putJson("/api/edu-topics/{$id}/trainers", ['trainer_ids' => [$b->id]])->assertOk()->assertJsonPath('trainers.0.full_name', 'Втор Тест');

        $this->getJson('/api/edu-topics')->assertOk()->assertJsonPath('0.trainers_count', 1);
        $this->deleteJson("/api/edu-topics/{$id}")->assertStatus(204);
        $this->assertSame(0, $b->topics()->count());
    }

    public function test_trainers_crud_with_matrix_and_topics(): void
    {
        $topic = EduTopic::create(['title' => 'Тема']);
        $skopje = $this->municipality('Град Скопје');
        $tetovo = $this->municipality('Тетово');

        $created = $this->postJson('/api/edu-trainers', [
            'full_name' => 'Нова Обучувачка',
            'full_name_sq' => 'Nova Obucuvacka',
            'languages' => [],
            'home_municipality_id' => $skopje->id,
            'hubs' => [
                ['hub_municipality_id' => $skopje->id],
                ['hub_municipality_id' => $skopje->id],
                ['hub_municipality_id' => $tetovo->id, 'topic_id' => $topic->id],
            ],
            'topic_ids' => [$topic->id],
            'email' => 'Nova@Example.com',
        ])->assertStatus(201)
            ->assertJsonPath('languages', [])
            ->assertJsonPath('hub_ids', [$skopje->id])
            ->assertJsonPath('topic_ids', [$topic->id])
            ->assertJsonPath('home_municipality.name', 'Град Скопје')
            ->assertJsonPath('email', 'nova@example.com')
            ->assertJsonCount(2, 'hubs')
            ->assertJsonPath('topics.0.title', 'Тема');
        $id = $created->json('id');

        // Only centres; existing topics.
        $this->postJson('/api/edu-trainers', ['full_name' => 'X', 'hubs' => [['hub_municipality_id' => $this->municipality('Липково')->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('hubs');
        $this->postJson('/api/edu-trainers', ['full_name' => 'X', 'topic_ids' => [99999]])->assertStatus(422)->assertJsonValidationErrors('topic_ids');
        $this->postJson('/api/edu-trainers', ['languages' => ['de']])->assertStatus(422)->assertJsonValidationErrors(['full_name', 'languages.0']);

        $this->putJson("/api/edu-trainers/{$id}", ['languages' => ['sq'], 'hubs' => [['hub_municipality_id' => $tetovo->id]]])->assertOk()
            ->assertJsonPath('languages', ['sq'])->assertJsonPath('hub_ids', [$tetovo->id])->assertJsonPath('topic_ids', [$topic->id]);

        EduTrainer::create(['full_name' => 'Друг Обучувач', 'name_key' => 'друг обучувач', 'languages' => ['mk'], 'is_active' => false]);

        $this->getJson('/api/edu-trainers')->assertOk()->assertJsonCount(2)
            ->assertJsonStructure([['id', 'full_name', 'full_name_sq', 'languages', 'home_municipality', 'hub_ids', 'topic_ids']]);
        $this->getJson('/api/edu-trainers?language=sq')->assertJsonCount(1)->assertJsonPath('0.id', $id);
        $this->getJson("/api/edu-trainers?hub_id={$tetovo->id}")->assertJsonCount(1);
        $this->getJson("/api/edu-trainers?topic_id={$topic->id}")->assertJsonCount(1);
        $this->getJson('/api/edu-trainers?active=0')->assertJsonCount(1)->assertJsonPath('0.full_name', 'Друг Обучувач');
        $this->getJson('/api/edu-trainers?search=obucuv')->assertJsonCount(1);
        $this->getJson("/api/edu-trainers/{$id}")->assertOk()->assertJsonPath('full_name_sq', 'Nova Obucuvacka');

        $this->deleteJson("/api/edu-trainers/{$id}")->assertStatus(204);
        $this->assertSame(0, $topic->trainers()->count());
    }

    public function test_locations_crud_and_copy_from_school(): void
    {
        $school = $this->school('СОУ „Тест“', 'Тетово', 'secondary');
        $school->update(['address' => 'ул. Тест 1', 'phones' => ['044111111']]);

        $this->postJson('/api/edu-locations', ['school_id' => $school->id])->assertStatus(201)
            ->assertJsonPath('name', 'СОУ „Тест“')
            ->assertJsonPath('kind', 'school')
            ->assertJsonPath('municipality.name', 'Тетово')
            ->assertJsonPath('address', 'ул. Тест 1')
            ->assertJsonPath('contact', '044111111');

        $hotel = $this->postJson('/api/edu-locations', ['name' => 'Хотел Тест', 'kind' => 'hotel', 'municipality_id' => $this->municipality('Охрид')->id, 'capacity' => 40])
            ->assertStatus(201)->json('id');
        $this->postJson('/api/edu-locations', ['kind' => 'hotel'])->assertStatus(422)->assertJsonValidationErrors(['name', 'municipality_id']);
        $this->postJson('/api/edu-locations', ['name' => 'X', 'kind' => 'tent', 'municipality_id' => $this->municipality('Охрид')->id, 'capacity' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['kind', 'capacity']);

        $this->getJson('/api/edu-locations?kind=hotel')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Хотел Тест');
        $this->getJson('/api/edu-locations?region_id=' . EduRegion::where('name', 'Полошки')->value('id'))->assertJsonCount(1);
        $this->getJson('/api/edu-locations?search=' . urlencode('Хотел'))->assertJsonCount(1);

        $this->putJson("/api/edu-locations/{$hotel}", ['capacity' => 50])->assertOk()->assertJsonPath('capacity', 50);
        $this->deleteJson("/api/edu-locations/{$hotel}")->assertStatus(204);
        $this->assertSame(1, EduLocation::count());
    }
}
