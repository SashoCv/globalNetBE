<?php

namespace Tests\Feature\Edu;

use App\Models\EduImport;
use App\Models\EduMunicipality;
use App\Models\EduSchool;
use App\Models\EduTrainer;
use App\Models\User;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EduWorkbookFactory;
use Tests\TestCase;

class EduImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(EduGeoSeeder::class);
        Sanctum::actingAs(User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret', 'role' => 'admin']));
    }

    private function upload(?EduWorkbookFactory $factory = null, array $extra = [])
    {
        $file = UploadedFile::fake()->createWithContent('obuki.xlsx', ($factory ?? EduWorkbookFactory::make())->contents());

        return $this->post('/api/edu-imports/workbook', ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    public function test_upload_preview_resolve_apply(): void
    {
        $factory = EduWorkbookFactory::make();
        $factory->primary[] = ['Скопски регион', 'Атлантида', 'ООУ „Изгубено“', '', '', '', ''];

        // 1. Upload: parsed and previewed, nothing written.
        $preview = $this->upload($factory)->assertStatus(201)
            ->assertJsonPath('status', 'previewed')
            ->assertJsonPath('sheets.secondary', 'АДРЕСИ И ТЕЛЕФОНИ СРЕДНИ')
            ->assertJsonPath('summary.schools_primary.created', 6)
            ->assertJsonPath('summary.schools_primary.errors', 1)
            ->assertJsonPath('errors.0.ref', 'primary:9')
            ->assertJsonPath('errors.0.code', 'unknown_municipality')
            ->assertJsonPath('can_apply', false)
            ->assertJsonStructure(['import_id', 'summary' => ['schools_secondary', 'schools_primary', 'trainers', 'topics'], 'errors' => [['ref', 'sheet', 'row', 'column', 'message']], 'needs_choice', 'warnings']);
        $id = $preview->json('import_id');
        $this->assertSame(0, EduSchool::count());

        $import = EduImport::find($id);
        Storage::disk('local')->assertExists($import->path);
        Storage::disk('local')->assertExists($import->preview_path);

        // 2. Applying with an unresolved error is refused.
        $this->postJson("/api/edu-imports/{$id}/apply")->assertStatus(422)->assertJsonPath('errors.0.ref', 'primary:9');
        $this->assertSame(0, EduSchool::count());

        // "Ана Тестоска" on the topic list looks like "Ана Тестовска" from the matrix.
        $duplicate = collect($preview->json('needs_choice'))->firstWhere('kind', 'trainer_duplicate');
        $this->assertSame('topic1:7', $duplicate['ref']);
        $this->assertSame('trainers:3', $duplicate['candidates'][0]['ref']);
        $this->assertSame('create', $duplicate['default']);

        // 3. Resolve: pick the municipality for the bad row, merge the trainer,
        //    confirm a trainer's language.
        $centar = EduMunicipality::where('name', 'Центар')->value('id');
        $this->putJson("/api/edu-imports/{$id}/rows", ['rows' => [
            ['ref' => 'primary:9', 'municipality_id' => $centar],
            ['ref' => 'topic1:7', 'merge_ref' => 'trainers:3'],
            ['ref' => 'topic2:7', 'languages' => ['sq']],
        ]])->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('summary.schools_primary.created', 7)
            ->assertJsonPath('resolutions.primary:9.municipality_id', $centar);

        $this->getJson("/api/edu-imports/{$id}")->assertOk()->assertJsonPath('can_apply', true)->assertJsonPath('status', 'previewed');

        // 4. Apply.
        $this->postJson("/api/edu-imports/{$id}/apply")->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('created', 7 + 7 + 9 + 2)
            ->assertJsonPath('updated', 0);
        $this->assertSame(14, EduSchool::count());
        $this->assertSame(['sq'], EduTrainer::where('full_name', 'Latin Tester')->first()->languages);
        $this->assertSame(['Ана Тестоска'], EduTrainer::where('full_name', 'Ана Тестовска')->first()->aliases);
        $this->assertSame(9, EduTrainer::count());
        $this->assertSame('applied', EduImport::find($id)->status);

        // Applied imports are closed.
        $this->postJson("/api/edu-imports/{$id}/apply")->assertStatus(409);
        $this->putJson("/api/edu-imports/{$id}/rows", ['rows' => [['ref' => 'primary:9', 'skip' => true]]])->assertStatus(409);

        // 5. The same workbook again changes nothing; the merged spelling is now
        //    a known alias. (The new upload has its own, empty, choices.)
        $this->upload($factory)->assertStatus(201)
            ->assertJsonPath('totals.created', 0)
            ->assertJsonPath('totals.updated', 0)
            ->assertJsonPath('errors.0.ref', 'primary:9');
    }

    public function test_a_skipped_row_is_not_imported(): void
    {
        $factory = EduWorkbookFactory::make();
        $factory->primary[] = ['Скопски регион', 'Атлантида', 'ООУ „Изгубено“', '', '', '', ''];
        $id = $this->upload($factory)->json('import_id');

        $this->putJson("/api/edu-imports/{$id}/rows", ['rows' => [['ref' => 'primary:9', 'skip' => true]]])->assertOk()
            ->assertJsonPath('summary.schools_primary.skipped', 1)
            ->assertJsonPath('can_apply', true);
        $this->postJson("/api/edu-imports/{$id}/apply")->assertOk()->assertJsonPath('skipped', 1);
        $this->assertSame(0, EduSchool::where('name_key', 'изгубено')->count());
    }

    public function test_only_some_sheets_and_matrix_topic(): void
    {
        $this->upload(null, ['sheets' => ['primary']])->assertStatus(201)
            ->assertJsonPath('sheets', ['primary' => 'Основни училишта'])
            ->assertJsonMissingPath('summary.trainers');

        $this->upload(null, ['matrix_topic_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('matrix_topic_id');
    }

    public function test_bad_files_are_rejected(): void
    {
        $this->post('/api/edu-imports/workbook', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $text = UploadedFile::fake()->createWithContent('notes.xlsx', 'not a spreadsheet');
        $this->post('/api/edu-imports/workbook', ['file' => $text], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_discard_deletes_the_files(): void
    {
        $id = $this->upload()->json('import_id');
        $import = EduImport::find($id);

        $this->deleteJson("/api/edu-imports/{$id}")->assertStatus(204);

        Storage::disk('local')->assertMissing($import->path);
        Storage::disk('local')->assertMissing($import->preview_path);
        $this->assertSame('discarded', $import->fresh()->status);
        $this->postJson("/api/edu-imports/{$id}/apply")->assertStatus(409);
    }
}
