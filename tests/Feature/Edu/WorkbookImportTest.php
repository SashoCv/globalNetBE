<?php

namespace Tests\Feature\Edu;

use App\Models\EduMunicipality;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use App\Models\EduTrainerHub;
use App\Services\Edu\EduWorkbookImporter;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduWorkbookFactory;
use Tests\TestCase;

class WorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EduGeoSeeder::class);
        $this->path = tempnam(sys_get_temp_dir(), 'eduwb') . '.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function import(?EduWorkbookFactory $factory = null, array $options = [], bool $commit = true): array
    {
        ($factory ?? EduWorkbookFactory::make())->save($this->path);
        $importer = new EduWorkbookImporter;

        return $importer->run($importer->parse($this->path), $options + ['trainer_aliases' => EduWorkbookFactory::aliases()], $commit);
    }

    public function test_row_counts_and_sheet_detection(): void
    {
        $result = $this->import();

        $this->assertTrue($result['applied']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Oбучувачи SREDNI', $result['sheets']['trainers']);
        $this->assertSame(7, EduSchool::where('level', 'secondary')->count());
        $this->assertSame(6, EduSchool::where('level', 'primary')->count());
        $this->assertSame(2, EduTopic::count());
        // 6 in the matrix + "Нов Тестер", "Тест Програмер", "Latin Tester"; the
        // others on the topic lists are the same people as in the matrix.
        $this->assertSame(9, EduTrainer::count());
        $this->assertSame(7, $result['summary']['schools_secondary']['created']);
        $this->assertSame(9, $result['summary']['trainers']['created']);
    }

    public function test_a_dry_run_writes_nothing_but_reports_the_same_counts(): void
    {
        $dry = $this->import(commit: false);

        $this->assertFalse($dry['applied']);
        $this->assertSame(0, EduSchool::count());
        $this->assertSame(0, EduTrainer::count());
        $this->assertSame(13, $dry['summary']['schools_secondary']['created'] + $dry['summary']['schools_primary']['created']);
    }

    public function test_importing_twice_creates_and_changes_nothing(): void
    {
        $this->import();
        $second = $this->import();

        $this->assertSame(0, $second['totals']['created']);
        $this->assertSame(0, $second['totals']['updated']);
        $this->assertSame(0, $second['summary']['topics']['links_created']);
        $this->assertSame(13, EduSchool::count());
        $this->assertSame(9, EduTrainer::count());
    }

    public function test_secondary_school_columns(): void
    {
        $this->import();
        $school = EduSchool::where('name_key', 'гимназија тест еден')->first();

        $this->assertSame('СОУ Гимназија „Тест Еден“ / SHMK Gjimnazi „Test Nje“', $school->display_name);
        $this->assertSame('СОУ Гимназија „Тест Еден“', $school->name);
        $this->assertSame('SHMK Gjimnazi „Test Nje“', $school->name_sq);
        $this->assertSame('ул. „Тестова“ бр. 12/3', $school->address);
        $this->assertSame('Rr. „Testova“ nr. 12/3', $school->address_sq);
        $this->assertSame(['mk', 'sq'], $school->languages);
        $this->assertSame(['043111111'], $school->phones);
        $this->assertSame('Петар Тестовски', $school->director_name); // column I
        $this->assertSame('Петар 070111111', $school->director_contact); // column J
        $this->assertSame(['prv.test@yahoo.com'], $school->emails);
        $this->assertSame('sou-testeden-veles@schools.mk', $school->schools_mk_email);
        $this->assertSame(1, $school->source_no);
    }

    public function test_odd_cells_are_cleaned(): void
    {
        $result = $this->import();

        // Same name, different type prefix: two schools, prefix kept in the key.
        $gostivar = EduMunicipality::where('name', 'Гостивар')->first();
        $this->assertEqualsCanonicalizing(['соу тестивар', 'сому тестивар'], EduSchool::where('municipality_id', $gostivar->id)->pluck('name_key')->all());

        // Extra text after the municipality goes to the comment.
        $radovish = EduSchool::where('name_key', 'тест сусинов')->first();
        $this->assertSame('Радовиш', $radovish->municipality->name);
        $this->assertSame('Тест 070-000-000', $radovish->comment);

        // Bilingual municipality, Latin E in the prefix, "Град Скопје" kept apart.
        $this->assertSame('Бутел', EduSchool::where('name_key', 'тест јовков')->first()->municipality->name);
        $this->assertSame('СЕПУГС', EduSchool::where('name_key', 'тест јовков')->first()->type_prefix);
        $this->assertSame('Град Скопје', EduSchool::where('name_key', 'тест марку')->first()->municipality->name);

        // Primary: empty language is Macedonian, director phone, villages, look-alike letters.
        $this->assertSame(['mk'], EduSchool::where('name_key', 'тест конески')->first()->languages);
        $jablanica = EduSchool::where('name_key', 'гоце тестов с јабланица')->first();
        $this->assertSame(['023111222'], $jablanica->phones);
        $this->assertSame('070123456', $jablanica->director_contact);
        $this->assertSame(['sq'], $jablanica->languages);
        $this->assertNotNull(EduSchool::where('name_key', 'гоце тестов с подгорци')->first());
        $this->assertSame(['kontakt@test-bitola.mk'], EduSchool::where('name_key', 'тест груев')->first()->emails);
        $this->assertSame('Македонска Каменица', EduSchool::where('name_key', 'тест каменички')->first()->municipality->name);
        $this->assertSame('Чешиново-Облешево', EduSchool::where('name_key', 'тест облешевски с облешево')->first()->municipality->name);

        // Reported, not fatal.
        $codes = collect($result['warnings'])->pluck('code');
        $this->assertContains('region_mismatch', $codes);
        $this->assertContains('municipality_extra_text', $codes);
        $this->assertContains('schools_mk_duplicate', $codes);
        $this->assertContains('schools_mk_domain', $codes);
        $this->assertSame('secondary:5', collect($result['warnings'])->firstWhere('code', 'schools_mk_place')['ref']); // Радовиш school, "-strumica" address
        // Македонски Брод keeps its own region.
        $this->assertSame('Југозападен', EduSchool::where('name_key', 'св тест охридски')->first()->municipality->region->name);
    }

    public function test_matrix_columns_are_matched_by_header_and_the_topic_is_set(): void
    {
        $topic = EduTopic::create(['title' => 'Матрична тема']);
        $this->import(options: ['matrix_topic_id' => $topic->id]);

        $trainer = EduTrainer::where('full_name', 'Тест Гевгелиски')->first();
        $hubs = EduTrainerHub::where('trainer_id', $trainer->id)->with('hub')->get();

        // "Гевгел" (no dot) is Гевгелија.
        $this->assertEqualsCanonicalizing(['Струмица', 'Гевгелија'], $hubs->pluck('hub.name')->all());
        $this->assertSame([$topic->id], $hubs->pluck('topic_id')->unique()->values()->all());
        $this->assertSame(['mk'], $trainer->languages);
        $this->assertSame('Струмица', $trainer->homeMunicipality->name);

        // Abbreviated home towns.
        $this->assertSame('Свети Николе', EduTrainer::where('full_name', 'Тест Николски')->first()->homeMunicipality->name);
        $this->assertSame('Кавадарци', EduTrainer::where('full_name', 'Тест Кавадарски')->first()->homeMunicipality->name);
    }

    public function test_the_default_matrix_has_no_topic(): void
    {
        $this->import();

        $this->assertSame(0, EduTrainerHub::whereNotNull('topic_id')->count());
        $this->assertSame(11, EduTrainerHub::count());
    }

    public function test_spellings_are_merged_across_sheets(): void
    {
        $this->import();

        // Alias data: "Ана Тестоска" on the topic list is "Ана Тестовска" from the matrix.
        $ana = EduTrainer::where('full_name', 'Ана Тестовска')->first();
        $this->assertSame('Ana Testovska', $ana->full_name_sq);
        $this->assertContains('Ана Тестоска', $ana->aliases);
        $this->assertSame(['mk'], $ana->languages);
        $this->assertSame(1, $ana->topics()->count());
        $this->assertSame(2, $ana->hubs()->count());

        // Latin and Cyrillic spellings of one name.
        $osmani = EduTrainer::where('full_name', 'Тест Османи')->first();
        $this->assertSame('Test Osmani', $osmani->full_name_sq);
        $this->assertSame(['sq'], $osmani->languages);
        $this->assertSame(1, $osmani->topics()->count());

        // Trailing space on the topic list.
        $this->assertSame(1, EduTrainer::where('full_name', 'Тест Обучувачка Прва')->count());
    }

    public function test_topic_lists(): void
    {
        $result = $this->import();

        $first = EduTopic::where('title', 'Тема за тестирање на увозот')->first();
        $second = EduTopic::where('title', 'Втора тема за безбедно, одговорно тестирање')->first();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(4, $first->trainers()->count());
        $this->assertSame(2, $second->trainers()->count());

        // Only on a topic list: no language yet, education and home from the list.
        $new = EduTrainer::where('full_name', 'Нов Тестер')->first();
        $this->assertSame([], $new->languages);
        $this->assertSame('Педагог', $new->education);
        $this->assertSame('Кривогаштани', $new->homeMunicipality->name);
        $this->assertSame([], EduTrainer::where('full_name', 'Тест Програмер')->first()->languages);

        // A Latin name: Albanian is set without asking, and reported.
        $latin = EduTrainer::where('full_name', 'Latin Tester')->first();
        $this->assertSame(['sq'], $latin->languages);
        $this->assertSame('Latin Tester', $latin->full_name_sq);
        $this->assertNull(collect($result['needs_choice'])->firstWhere('kind', 'trainer_language'));
        $warning = collect($result['warnings'])->firstWhere('code', 'trainer_language_sq');
        $this->assertSame('topic2:7', $warning['ref']);
        $this->assertSame(1, $result['summary']['trainers']['language_from_script']);
    }

    public function test_a_language_choice_overrides_the_latin_name_rule(): void
    {
        $result = $this->import(options: ['resolutions' => ['topic2:7' => ['languages' => ['mk']]]]);

        $this->assertSame(['mk'], EduTrainer::where('full_name', 'Latin Tester')->first()->languages);
        $this->assertSame(0, $result['summary']['trainers']['language_from_script']);
    }

    public function test_reimport_keeps_languages_set_in_the_app(): void
    {
        $this->import();
        EduTrainer::where('full_name', 'Latin Tester')->first()->update(['languages' => ['mk']]);
        EduSchool::where('name_key', 'тест конески')->first()->update(['languages' => ['sq']]);

        $second = $this->import();

        $this->assertSame(['mk'], EduTrainer::where('full_name', 'Latin Tester')->first()->languages);
        $this->assertSame(['sq'], EduSchool::where('name_key', 'тест конески')->first()->languages);
        $this->assertSame(0, $second['totals']['updated']);
        $this->assertSame(0, $second['summary']['trainers']['language_from_script']);
        $this->assertSame(0, $second['summary']['schools_primary']['default_language']);
    }

    public function test_reimport_fills_languages_that_are_still_unknown(): void
    {
        $this->import();
        EduTrainer::where('full_name', 'Latin Tester')->first()->update(['languages' => []]);
        EduSchool::where('name_key', 'тест конески')->first()->update(['languages' => []]);

        $second = $this->import();

        $this->assertSame(['sq'], EduTrainer::where('full_name', 'Latin Tester')->first()->languages);
        $this->assertSame(['mk'], EduSchool::where('name_key', 'тест конески')->first()->languages);
        $this->assertSame(1, $second['summary']['schools_primary']['default_language']);
    }

    public function test_without_a_default_school_language_an_empty_cell_stays_unknown(): void
    {
        config(['edu.import.school_default_language' => null]);

        $result = $this->import();

        $this->assertSame([], EduSchool::where('name_key', 'тест конески')->first()->languages);
        $this->assertSame(['sq'], EduSchool::where('name_key', 'гоце тестов с јабланица')->first()->languages);
        $this->assertSame(0, $result['summary']['schools_primary']['default_language']);
    }

    public function test_the_regions_sheet_moves_municipalities_and_centres(): void
    {
        $factory = EduWorkbookFactory::make();
        $factory->regions = [
            ['Пелагониски регион', 'Македонски Брод', 'Прилеп'],
            ['', 'Кичево', 'Охрид'],          // merged region cell
            ['Нов Реон', 'Ново Место', 'Охрид'], // unknown municipality is created
        ];

        $result = $this->import($factory);

        $brod = EduMunicipality::where('name', 'Македонски Брод')->first();
        $this->assertSame('Пелагониски', $brod->region->name);
        $kicevo = EduMunicipality::where('name', 'Кичево')->first();
        $this->assertSame('Пелагониски', $kicevo->region->name);
        $this->assertSame('Охрид', $kicevo->hub->name);
        $this->assertNotNull(EduRegion::where('name', 'Нов реон')->first() ?? EduRegion::where('name', 'Нов Реон')->first());
        $this->assertSame('Охрид', EduMunicipality::where('name', 'Ново Место')->first()->hub->name);
        $this->assertSame(2, $result['summary']['municipalities']['updated']);
        $this->assertSame(1, $result['summary']['municipalities']['created']);

        // The secondary school in Македонски Брод now agrees with its region.
        $this->assertNotContains('region_mismatch', collect($result['warnings'])->pluck('code'));
    }

    public function test_rows_with_errors_block_apply_unless_skipped(): void
    {
        $factory = EduWorkbookFactory::make();
        $factory->primary[] = ['Скопски регион', 'Атлантида', 'ООУ „Изгубено“', '', '', '', ''];

        $blocked = $this->import($factory);
        $this->assertFalse($blocked['applied']);
        $this->assertSame(0, EduSchool::count());
        $this->assertSame('unknown_municipality', $blocked['errors'][0]['code']);
        $this->assertSame('primary:9', $blocked['errors'][0]['ref']);
        $this->assertSame('B', $blocked['errors'][0]['column']);

        $resolved = $this->import($factory, ['resolutions' => ['primary:9' => ['municipality_id' => EduMunicipality::where('name', 'Центар')->value('id')]]]);
        $this->assertTrue($resolved['applied']);
        $this->assertSame('Центар', EduSchool::where('name_key', 'изгубено')->first()->municipality->name);
    }

    public function test_without_geography_the_import_stops(): void
    {
        EduMunicipality::query()->update(['hub_municipality_id' => null]);
        EduMunicipality::query()->delete();

        $result = $this->import();

        $this->assertFalse($result['applied']);
        $this->assertSame('no_geography', $result['errors'][0]['code']);
    }
}
