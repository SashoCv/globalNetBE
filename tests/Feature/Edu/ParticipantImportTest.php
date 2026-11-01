<?php

namespace Tests\Feature\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduImport;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EduParticipantFileFactory;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class ParticipantImportTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    private EduProgram $program;
    private EduSchool $goce;
    private EduSchool $bratstvo;
    private EduSchool $unknown;
    private EduSchool $naim;

    private const HEADER = ['Ред. бр.', 'Име и презиме', 'Училиште', 'Општина', 'Јазик', 'E-mail', 'Телефон', 'Работно место', 'Забелешка'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->eduSetUp();
        $this->program = $this->eduProgram();
        $this->goce = $this->eduSchool('ООУ „Гоце Делчев“', 'Велес', ['mk']);
        $this->bratstvo = $this->eduSchool('ООУ „Братство“', 'Тетово', ['mk', 'sq']);
        $this->unknown = $this->eduSchool('ООУ „Непознат Јазик“', 'Кавадарци', []);
        $this->naim = $this->eduSchool('ООУ „Наим Фрашери“', 'Тетово', ['sq']);
    }

    private function rows(): array
    {
        return [
            [1, 'Ана Тестовска', 'ООУ Гоце Делчев', 'Велес', '', 'shared@school.mk', '070 123 456', 'педагог', 'вегетаријанец'],
            [2, 'Бојан Тестовски', 'ООУ ,,Гоце Делчев“', 'Велес', 'мк', 'Shared@School.mk', '', 'психолог', ''],
            [3, 'Ардита Тест', 'ООУ Братство', 'Тетово', '', '', '', '', ''],
            [4, 'Џемиле Тест', 'ООУ Братство', 'Тетово', 'тр', '', '', '', ''],
            [5, 'Непознат Учесник', 'ООУ Атлантида', 'Велес', 'мк', '', '', '', ''],
            [6, 'Лирие Тест', 'ООУ „Наим Фрашери“', 'Тетово', 'алб', 'lirie@test.mk', '', '', ''],
            [7, 'Петар Тест', 'ООУ Непознат Јазик', 'Кавадарци', '', '', '', '', ''],
            [8, 'Мила Мешана', 'ООУ Гоце Делчев', 'Велес', 'мк/алб', '', '', '', ''],
        ];
    }

    private function upload(string $content, string $name = 'ucesnici.xlsx', array $extra = [], string $route = 'participants')
    {
        $file = UploadedFile::fake()->createWithContent($name, $content);

        return $this->post("/api/edu-programs/{$this->program->id}/{$route}/import", ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function resolveAll(int $importId): void
    {
        $this->putJson("/api/edu-imports/{$importId}/rows", ['rows' => [
            ['ref' => 'participants:5', 'language' => 'sq'],
            ['ref' => 'participants:7', 'school_id' => $this->goce->id],
            ['ref' => 'participants:9', 'skip' => true],
            ['ref' => 'participants:10', 'language' => 'mk'],
        ]])->assertOk()->assertJsonPath('can_apply', true);
    }

    public function test_upload_preview_resolve_apply_and_reimport(): void
    {
        // The program had counts and a group: the people fill it after the import.
        $this->eduCounts($this->program, $this->goce, 2);
        $group = EduGroup::create(['program_id' => $this->program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $this->goce->id, 'language' => 'mk', 'participant_count' => 2]);

        $content = EduParticipantFileFactory::xlsx(self::HEADER, $this->rows(), 'Учесници', ['Листа на учесници'], ['Упатство' => [['Текст'], [['нема табела']]]]);
        $preview = $this->upload($content)->assertStatus(201)
            ->assertJsonPath('type', 'participants')
            ->assertJsonPath('status', 'previewed')
            ->assertJsonPath('program_id', $this->program->id)
            ->assertJsonPath('sheet', 'Учесници')
            ->assertJsonPath('sheets', ['participants' => 'Учесници'])
            ->assertJsonPath('available_sheets', ['Упатство', 'Учесници'])
            ->assertJsonPath('header_row', 2)
            ->assertJsonPath('detected_columns.full_name', ['column' => 'B', 'header' => 'Име и презиме'])
            ->assertJsonPath('detected_columns.school.column', 'C')
            ->assertJsonPath('detected_columns.position.column', 'H')
            ->assertJsonPath('extra_columns', ['Забелешка'])
            ->assertJsonCount(8, 'rows')
            ->assertJsonPath('summary.participants.created', 4)
            ->assertJsonPath('can_apply', false)
            ->assertJsonStructure(['rows' => [['ref', 'row', 'full_name', 'school_text', 'school_id', 'school_name', 'school_candidates', 'municipality_id', 'language', 'email', 'phone', 'position', 'status', 'action', 'errors', 'warnings']]]);
        $id = $preview->json('import_id');

        $errors = collect($preview->json('errors'))->pluck('code', 'ref');
        $this->assertSame([
            'participants:5' => 'unknown_language',  // two-language school, empty cell
            'participants:7' => 'unknown_school',
            'participants:9' => 'unknown_language',  // school language unknown
            'participants:10' => 'mixed_language',
        ], $errors->all());
        $this->assertSame('school_match', collect($preview->json('needs_choice'))->firstWhere('ref', 'participants:7')['kind']);
        $this->assertSame(['mk', 'sq'], array_column(collect($preview->json('needs_choice'))->firstWhere('ref', 'participants:5')['candidates'], 'code'));
        $rows = collect($preview->json('rows'))->keyBy('ref');
        $this->assertSame('mk', $rows['participants:3']['language']); // the school's only language
        $this->assertSame($this->goce->id, $rows['participants:3']['school_id']);
        $this->assertSame('shared@school.mk', $rows['participants:4']['email']);
        $this->assertSame('tr', $rows['participants:6']['language']);
        $this->assertSame('error', $rows['participants:7']['status']);
        $this->assertSame('create', $rows['participants:3']['action']);

        // Nothing written by the preview, and unknown schools block applying.
        $this->assertSame(0, EduParticipant::count());
        $this->assertSame('counts', $this->program->fresh()->participant_mode);
        $this->postJson("/api/edu-imports/{$id}/apply")->assertStatus(422);
        $this->assertSame(0, EduParticipant::count());

        $this->resolveAll($id);
        $this->getJson("/api/edu-imports/{$id}")->assertOk()
            ->assertJsonPath('summary.participants.created', 7)
            ->assertJsonPath('summary.participants.skipped', 1)
            ->assertJsonPath('rows.6.status', 'skipped');

        $this->postJson("/api/edu-imports/{$id}/apply")->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('created', 7)
            ->assertJsonPath('skipped', 1);

        // Same e-mail, different names: two people.
        $this->assertSame(2, EduParticipant::where('email', 'shared@school.mk')->count());
        $this->assertSame(7, EduParticipant::where('program_id', $this->program->id)->count());
        $ana = EduParticipant::where('full_name', 'Ана Тестовска')->first();
        $this->assertSame(['Забелешка' => 'вегетаријанец'], $ana->extra);
        $this->assertSame('педагог', $ana->position);
        $this->assertSame('070123456', $ana->phone);
        $this->assertSame($id, $ana->import_id);

        // Persons mode; the counts come from the people.
        $program = $this->program->fresh();
        $this->assertSame('persons', $program->participant_mode);
        $counts = EduProgramSchool::where('program_id', $program->id)->get()->keyBy('school_id');
        $this->assertSame([4, 0, 0], array_values($counts[$this->goce->id]->only(['count_mk', 'count_sq', 'count_tr'])));
        $this->assertSame([0, 1, 1], array_values($counts[$this->bratstvo->id]->only(['count_mk', 'count_sq', 'count_tr'])));
        $this->assertSame(1, $counts[$this->naim->id]->count_sq);
        $this->assertSame('persons', $counts[$this->goce->id]->source);
        $this->assertFalse($counts->has($this->unknown->id));
        // The existing allocation got two of the people.
        $this->assertSame(2, EduParticipant::where('group_id', $group->id)->count());
        $this->assertSame(2, EduGroupSchool::where('group_id', $group->id)->value('participant_count'));

        // Re-import: nothing new, nobody twice.
        $again = $this->upload($content)->assertStatus(201)->json('import_id');
        $this->resolveAll($again);
        $this->postJson("/api/edu-imports/{$again}/apply")->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('unchanged', 7);
        $this->assertSame(7, EduParticipant::count());
        $this->assertSame(2, EduParticipant::where('group_id', $group->id)->count());

        // A changed phone is an update; people missing from a file are only reported.
        $rows = $this->rows();
        $rows[0][6] = '071 999 888';
        $short = $this->upload(EduParticipantFileFactory::xlsx(self::HEADER, [$rows[0]], 'Учесници', ['Листа на учесници']))->assertStatus(201)
            ->assertJsonPath('summary.participants.updated', 1)
            ->assertJsonPath('summary.participants.missing', 6);
        $this->assertContains('not_in_file', array_column($short->json('warnings'), 'code'));
        $this->postJson('/api/edu-imports/' . $short->json('import_id') . '/apply')->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('071999888', $ana->fresh()->phone);
        $this->assertSame(7, EduParticipant::count());
    }

    public function test_csv_and_a_given_header_row(): void
    {
        $csv = EduParticipantFileFactory::csv(['Name', 'Shkolla', 'Gjuha', 'Email'], [
            ['Lirie Test', 'ООУ „Наим Фрашери“', 'А', 'lirie@test.mk'],
            ['Fatime Test', 'ООУ „Наим Фрашери“', '', ''],
        ]);
        $id = $this->upload($csv, 'lista.csv')->assertStatus(201)
            ->assertJsonPath('header_row', 1)
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('rows.1.language', 'sq')
            ->json('import_id');
        $this->postJson("/api/edu-imports/{$id}/apply")->assertOk()->assertJsonPath('created', 2);
        $this->assertSame(2, EduProgramSchool::where('school_id', $this->naim->id)->value('count_sq'));

        // A file without a recognisable header.
        $this->upload(EduParticipantFileFactory::xlsx(['Колона 1', 'Колона 2'], [['а', 'б']]))->assertStatus(422)
            ->assertJsonPath('sheets', ['Учесници']);
        $this->upload($csv, 'lista.csv', ['sheet' => 'Нема го'])->assertStatus(422);

        // A file with no valid person does not turn a counts program into a persons one.
        $other = $this->eduProgram();
        $this->eduCounts($other, $this->goce, 5);
        $file = UploadedFile::fake()->createWithContent('x.csv', EduParticipantFileFactory::csv(['Име и презиме', 'Училиште', 'Јазик'], [['Некој', 'ООУ Атлантида', 'мк']]));
        $bad = $this->post("/api/edu-programs/{$other->id}/participants/import", ['file' => $file], ['Accept' => 'application/json'])->assertStatus(201)->json('import_id');
        $this->putJson("/api/edu-imports/{$bad}/rows", ['rows' => [['ref' => 'participants:2', 'skip' => true]]])->assertOk()->assertJsonPath('can_apply', true);
        $this->postJson("/api/edu-imports/{$bad}/apply")->assertOk()->assertJsonPath('created', 0);
        $this->assertSame('counts', $other->fresh()->participant_mode);
        $this->assertSame(5, EduProgramSchool::where('program_id', $other->id)->value('count_mk'));
        $this->post("/api/edu-programs/{$this->program->id}/participants/import", [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // The header row can be forced.
        $content = EduParticipantFileFactory::xlsx(self::HEADER, [$this->rows()[0]], 'Учесници', ['Листа', 'Име и презиме', '']);
        $this->upload($content, 'x.xlsx', ['header_row' => 4])->assertStatus(201)->assertJsonPath('header_row', 4)->assertJsonCount(1, 'rows');
    }

    public function test_school_counts_import(): void
    {
        $header = ['Училиште', 'Општина', 'М', 'А', 'Т', 'Вк'];
        $rows = [
            ['ООУ Гоце Делчев', 'Велес', '5', '', '1', '6'],
            ['ООУ „Наим Фрашери“', 'Тетово', '', '', '', '7'],
            ['ООУ Братство', 'Тетово', '', '', '', '4'],
            ['ООУ Непознат Јазик', 'Кавадарци', '2', '1', '0', '9'],
            ['Вкупно', '', '7', '1', '1', '26'],
        ];
        $preview = $this->upload(EduParticipantFileFactory::xlsx($header, $rows, 'Бројки'), 'broj.xlsx', [], 'school-counts')->assertStatus(201)
            ->assertJsonPath('type', 'school_counts')
            ->assertJsonPath('detected_columns.count_mk.column', 'C')
            ->assertJsonPath('detected_columns.count_total.column', 'F')
            ->assertJsonCount(4, 'rows')
            ->assertJsonPath('errors.0.ref', 'counts:4')
            ->assertJsonPath('errors.0.code', 'unknown_language')
            ->assertJsonPath('rows.1.count_sq', 7)
            ->assertJsonPath('can_apply', false);
        $this->assertContains('total_mismatch', array_column($preview->json('warnings'), 'code'));
        $id = $preview->json('import_id');

        $this->putJson("/api/edu-imports/{$id}/rows", ['rows' => [['ref' => 'counts:4', 'language' => 'mk']]])->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('summary.schools.created', 4);
        $this->postJson("/api/edu-imports/{$id}/apply")->assertOk()->assertJsonPath('created', 4);

        $counts = EduProgramSchool::where('program_id', $this->program->id)->get()->keyBy('school_id');
        $this->assertSame([5, 0, 1, 6], array_values($counts[$this->goce->id]->only(['count_mk', 'count_sq', 'count_tr', 'count_total'])));
        $this->assertSame(7, $counts[$this->naim->id]->count_sq);
        $this->assertSame(4, $counts[$this->bratstvo->id]->count_mk);
        $this->assertSame(3, $counts[$this->unknown->id]->count_total);
        $this->assertSame('import', $counts[$this->goce->id]->source);
        $this->assertSame('counts', $this->program->fresh()->participant_mode);

        // Lower than what groups already hold: refused for that school.
        $group = EduGroup::create(['program_id' => $this->program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $this->goce->id, 'language' => 'mk', 'participant_count' => 5]);
        $rows[0][2] = '3';
        $lower = $this->upload(EduParticipantFileFactory::xlsx($header, $rows, 'Бројки'), 'broj.xlsx', [], 'school-counts')->assertStatus(201)
            ->assertJsonPath('summary.schools.unchanged', 2);
        $this->assertSame(['counts:2' => 'below_allocated', 'counts:4' => 'unknown_language'], collect($lower->json('errors'))->pluck('code', 'ref')->sortKeys()->all());

        // A program with a list of people does not take counts.
        $this->program->update(['participant_mode' => 'persons']);
        $this->upload(EduParticipantFileFactory::xlsx($header, $rows), 'broj.xlsx', [], 'school-counts')->assertStatus(409);
        $this->assertSame(2, EduImport::where('program_id', $this->program->id)->count());
    }
}
