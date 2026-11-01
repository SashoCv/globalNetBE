<?php

namespace Tests\Feature\Edu;

use App\Models\EduMunicipality;
use App\Models\EduRegion;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EduGeoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_regions_municipalities_and_centres(): void
    {
        $this->seed(EduGeoSeeder::class);

        $this->assertSame(8, EduRegion::count());
        $this->assertSame(81, EduMunicipality::count());
        $this->assertSame(20, EduMunicipality::where('is_training_hub', true)->count());

        // Every municipality has a region and a centre, and centres point at themselves.
        $this->assertSame(0, EduMunicipality::whereNull('region_id')->orWhereNull('hub_municipality_id')->count());
        foreach (EduMunicipality::with('hub')->get() as $m) {
            $this->assertTrue($m->hub->is_training_hub, "{$m->name} → {$m->hub->name} is not a centre");
            if ($m->is_training_hub) {
                $this->assertSame($m->id, $m->hub_municipality_id);
                $this->assertNotNull($m->hub_short_name);
            }
        }

        $skopje = EduMunicipality::where('name', 'Град Скопје')->first();
        $this->assertTrue($skopje->is_training_hub);
        $this->assertTrue($skopje->part_of_skopje);
        $this->assertSame('Скопје', $skopje->hub_short_name);
        $this->assertSame($skopje->id, EduMunicipality::where('name', 'Карпош')->value('hub_municipality_id'));
        $this->assertTrue((bool) EduMunicipality::where('name', 'Карпош')->value('part_of_skopje'));
        $this->assertSame('Прилеп', EduMunicipality::where('name', 'Македонски Брод')->first()->hub->name);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed(EduGeoSeeder::class);
        $before = EduMunicipality::orderBy('id')->get(['id', 'name', 'region_id', 'hub_municipality_id'])->toArray();

        $this->seed(EduGeoSeeder::class);

        $this->assertSame(8, EduRegion::count());
        $this->assertSame($before, EduMunicipality::orderBy('id')->get(['id', 'name', 'region_id', 'hub_municipality_id'])->toArray());
    }
}
