<?php

namespace Tests\Unit\Edu;

use App\Models\EduMunicipality;
use App\Models\EduSchool;
use App\Services\Edu\EduMunicipalityResolver;
use App\Services\Edu\EduSchoolMatcher;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EduSchoolMatcherTest extends TestCase
{
    use RefreshDatabase;

    private EduMunicipality $struga;
    private EduMunicipality $ohrid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EduGeoSeeder::class);
        $this->struga = EduMunicipality::where('name', 'Струга')->first();
        $this->ohrid = EduMunicipality::where('name', 'Охрид')->first();

        $this->school('ООУ „Гоце Делчев“ с.Јабланица', $this->struga);
        $this->school('ООУ „Гоце Делчев“ с.Подгорци', $this->struga);
        $this->school('ООУ „Браќа Миладиновци“ с.Добовјани', $this->struga);
        $this->school('ООУ „Кочо Рацин“', $this->ohrid);
        $this->school('ООУ „Кочо Рацин“', EduMunicipality::where('name', 'Битола')->first());
    }

    private function school(string $name, EduMunicipality $m): EduSchool
    {
        return EduSchool::create(EduSchool::nameFields($name, $m->name) + [
            'level' => EduSchool::LEVEL_PRIMARY,
            'municipality_id' => $m->id,
        ]);
    }

    private function matchName(string $name, ?EduMunicipality $m = null): array
    {
        return (new EduSchoolMatcher)->match($name, EduSchool::LEVEL_PRIMARY, $m?->id);
    }

    public function test_latin_o_variants_match_exactly(): void
    {
        $result = $this->matchName('OОУ „Кочо Рацин“', $this->ohrid);

        $this->assertSame('exact', $result['method']);
        $this->assertSame(1.0, $result['score']);
        $this->assertSame($this->ohrid->id, EduSchool::find($result['school_id'])->municipality_id);
    }

    public function test_the_village_picks_the_right_school(): void
    {
        $result = $this->matchName('ОУ Гоце Делчев с. Подгорци', $this->struga);

        $this->assertSame('exact', $result['method']);
        $this->assertSame('Подгорци', EduSchool::find($result['school_id'])->settlement);
    }

    public function test_without_the_village_only_a_unique_school_matches(): void
    {
        $unique = $this->matchName('ООУ „Браќа Миладиновци“', $this->struga);
        $this->assertSame('without_settlement', $unique['method']);
        $this->assertSame('Добовјани', EduSchool::find($unique['school_id'])->settlement);

        // Two "Гоце Делчев" schools in Струга: a choice is needed.
        $ambiguous = $this->matchName('ООУ „Гоце Делчев“', $this->struga);
        $this->assertNull($ambiguous['school_id']);
        $this->assertCount(3, $ambiguous['candidates']);
        $this->assertStringContainsString('Гоце Делчев', $ambiguous['candidates'][0]['display_name']);
    }

    public function test_fuzzy_match_on_the_ascii_key(): void
    {
        $result = $this->matchName('ООУ „Гоце Делчев“ с.Јаблоница', $this->struga);

        $this->assertSame('fuzzy', $result['method']);
        $this->assertGreaterThanOrEqual(0.85, $result['score']);
        $this->assertSame('Јабланица', EduSchool::find($result['school_id'])->settlement);
    }

    public function test_the_municipality_narrows_the_search(): void
    {
        $bitola = EduMunicipality::where('name', 'Битола')->first();
        $this->assertSame($bitola->id, EduSchool::find($this->matchName('Кочо Рацин', $bitola)['school_id'])->municipality_id);

        // Without a municipality the same name is ambiguous across the level.
        $this->assertNull($this->matchName('Кочо Рацин')['school_id']);
    }

    public function test_the_municipality_resolver_cuts_extra_text(): void
    {
        $resolver = new EduMunicipalityResolver;

        $found = $resolver->resolve('Радовиш  Goce 070-790-497');
        $this->assertSame('Радовиш', $found['municipality']->name);
        $this->assertSame('Goce 070-790-497', $found['remainder']);

        $this->assertSame('Тетово', $resolver->resolve('Teтово/Tetovë')['municipality']->name);
        $this->assertSame('Чешиново-Облешево', $resolver->resolve('Чешиново Облешево')['municipality']->name);
        $this->assertSame('Македонска Каменица', $resolver->resolve('Македонска Kaменица')['municipality']->name);
        $this->assertSame('Кавадарци', $resolver->resolve('Кавад.')['municipality']->name);
        $this->assertSame('Град Скопје', $resolver->resolve('Скопје')['municipality']->name);
        $this->assertSame('Бутел', $resolver->resolve('Бутел/Butel')['municipality']->name);
        $this->assertNull($resolver->resolve('Атлантида')['municipality']);

        $this->assertSame('Гевгелија', $resolver->hubFromHeader('Гевгел')->name);
        $this->assertSame('Свети Николе', $resolver->hubFromHeader('Св.Никол')->name);
        $this->assertSame('Град Скопје', $resolver->hubFromHeader('Скопје')->name);
    }
}
