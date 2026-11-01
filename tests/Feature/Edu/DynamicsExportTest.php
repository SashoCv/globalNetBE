<?php

namespace Tests\Feature\Edu;

use App\Services\Edu\DynamicsExporter;
use App\Services\Edu\EduGroupOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\EduOutputFixtures;
use Tests\TestCase;

class DynamicsExportTest extends TestCase
{
    use EduOutputFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-15 10:00:00');
        $this->buildOutputFixtures();
    }

    private function sheet(TestResponse $response): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'dyn') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    /** @return list<string> */
    private function row(Worksheet $sheet, int $row, string $lastCol): array
    {
        return array_map(fn ($v) => $v === null ? '' : (string) $v, $sheet->rangeToArray("A{$row}:{$lastCol}{$row}", null, false, false)[0]);
    }

    public function test_standard_layout_mirrors_primer_dinamika(): void
    {
        $response = $this->get("/api/edu-programs/{$this->program->id}/dynamics.xlsx")->assertOk();
        $this->assertStringStartsWith(DynamicsExporter::MIME, $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'dinamika-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista-2026-05-15.xlsx',
            $response->headers->get('Content-Disposition'),
        );

        $sheet = $this->sheet($response);
        $this->assertSame('Динамика', $sheet->getTitle());

        // Headers literally from config, staff placeholder filled in.
        $this->assertSame([
            'Ред. Бр',
            'Место каде се реализира обуката',
            'Присутни стручни соработници од училиште',
            'Град',
            'Број на учесници',
            'Јазик',
            'Обучувач',
            'Дата на реализација на обука',
            'Време на реализација на обука',
        ], $this->row($sheet, 1, 'I'));
        $this->assertSame(array_values(str_replace(':staff', 'стручни соработници', config('edu.dynamics_headers.standard'))), $this->row($sheet, 1, 'I'));
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('C1')->getAlignment()->getWrapText());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertEquals(80, $sheet->getColumnDimension('C')->getWidth());

        // Sorted by number; the cancelled group 4 is left out.
        $this->assertSame([
            '1',
            'Хотел Турист',
            $this->schoolEntry('gostivar', 'Гостивар', '3') . ', ' . $this->schoolEntry('zef', 'Град Скопје', '2'),
            'Скопје',
            '5',
            'алб',
            'Lumturije Mehmedi',
            '30.06-01.07.2026',
            '9.00-13.00 (два дена)',
        ], $this->row($sheet, 2, 'I'));
        $this->assertSame([
            '2',
            $this->schools['panko']->fresh()->display_name,
            $this->schoolEntry('panko', 'Велес', '1+1т') . ', ' . $this->schoolEntry('todor', 'Чашка', '3'),
            'Велес',
            '5',
            'мк',
            'Катерина Димеска',
            '01-02.06.2026',
            '8.30-13.30 (два дена)',
        ], $this->row($sheet, 3, 'I'));
        $this->assertStringContainsString('„Панко Брашнаров“-Велес (1+1т)', $sheet->getCell('C3')->getValue());
        $this->assertSame(['3', $this->schools['gostivar']->fresh()->display_name, $this->schoolEntry('gostivar', 'Гостивар', '4'), 'Гостивар', '4', 'алб', '', '', ''], $this->row($sheet, 4, 'I'));

        // Total row with a SUM formula.
        $this->assertSame('Вкупно', $sheet->getCell('D5')->getValue());
        $this->assertSame('=SUM(E2:E4)', $sheet->getCell('E5')->getValue());
        $this->assertEquals(14, $sheet->getCell('E5')->getCalculatedValue());
        $this->assertTrue($sheet->getStyle('E5')->getFont()->getBold());
        $this->assertSame(5, $sheet->getHighestDataRow());
    }

    public function test_trainer_latin_name_only_for_albanian_groups_and_only_when_set(): void
    {
        $output = app(EduGroupOutput::class);
        $g1 = $output->load($this->groups[1]->fresh());
        $g2 = $output->load($this->groups[2]->fresh());
        $this->assertSame('Lumturije Mehmedi', $output->trainerName($g1));
        $this->assertSame('Катерина Димеска', $output->trainerName($g2));

        // An Albanian group with a trainer without a Latin form keeps the Cyrillic one.
        $this->groups[1]->update(['trainer_id' => $g2->trainer_id]);
        $this->assertSame('Катерина Димеска', $output->trainerName($output->load($this->groups[1]->fresh())));
    }

    public function test_count_labels(): void
    {
        $output = app(EduGroupOutput::class);
        $this->assertSame('1+1т', $output->countLabel(['mk' => 1, 'tr' => 1], 'mk'));
        $this->assertSame('1+1т', $output->countLabel(['tr' => 1, 'mk' => 1], 'mk'));
        $this->assertSame('3', $output->countLabel(['sq' => 3], 'sq'));
        $this->assertSame('2+1м', $output->countLabel(['sq' => 2, 'mk' => 1], 'sq'));
        $this->assertSame('4+2а', $output->countLabel(['mk' => 4, 'sq' => 2, 'tr' => 0], 'mk'));
        $this->assertSame('0', $output->countLabel(['mk' => 0], 'mk'));
    }

    public function test_host_layout_mirrors_dinamika_karierni(): void
    {
        $response = $this->get("/api/edu-programs/{$this->program->id}/dynamics.xlsx?format=host")->assertOk();
        $this->assertStringContainsString('dinamika-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista-domakini-2026-05-15.xlsx', $response->headers->get('Content-Disposition'));
        $sheet = $this->sheet($response);

        $this->assertSame(array_values(app(DynamicsExporter::class)->headers($this->program, 'host')), $this->row($sheet, 1, 'N'));
        $this->assertSame('Ред. бр. / Nr. rend.', $sheet->getCell('A1')->getValue());
        // The host school header follows the program's level (primary here).
        $this->assertSame('Име на основното училиште / Emri i shkollës fillore', $sheet->getCell('E1')->getValue());

        // Group 1 at a hotel in Град Скопје: no contact block.
        $this->assertSame([
            '1', 'Скопски регион', 'Скопје', 'Град Скопје', 'Хотел Турист', 'ул. Гиго Михајловски 1', '', '', '', '',
            $this->schoolEntry('gostivar', 'Гостивар', '3') . ', ' . $this->schoolEntry('zef', 'Град Скопје', '2'),
            'алб', '5', '',
        ], $this->row($sheet, 2, 'N'));

        // Group 3 at the host school: bilingual place names and the contact block.
        $this->assertSame([
            '3', 'Полошки регион', 'Гостивар/ Gostivar', 'Гостивар/ Gostivar', $this->schools['gostivar']->fresh()->display_name,
            'ул. „Букурешка“ бб / Rr. „Bukureshka“ pn.', '042/214-118', 'Арбен Шабани 070111222', 'sougostivar@yahoo.com', 'sou-gostivar@schools.mk',
            $this->schoolEntry('gostivar', 'Гостивар', '4'), 'алб', '4', '',
        ], $this->row($sheet, 4, 'N'));
        $this->assertSame('Вардарски регион', $sheet->getCell('B3')->getValue());
        $this->assertStringContainsString('(1+1т)', $sheet->getCell('K3')->getValue());

        $this->assertSame('Вкупно', $sheet->getCell('L5')->getValue());
        $this->assertSame('=SUM(M2:M4)', $sheet->getCell('M5')->getValue());
        $this->assertEquals(14, $sheet->getCell('M5')->getCalculatedValue());
        $this->assertSame('A2', $sheet->getFreezePane());

        // The plan's "layout" name is accepted too.
        $this->assertSame('Регион', $this->sheet($this->get("/api/edu-programs/{$this->program->id}/dynamics.xlsx?layout=host")->assertOk())->getCell('B1')->getValue());
    }

    public function test_host_school_header_follows_program_level(): void
    {
        $exporter = app(DynamicsExporter::class);
        $this->assertSame('Име на основното училиште / Emri i shkollës fillore', $exporter->headers($this->program, 'host')['host_school']);

        $this->program->school_level = 'secondary';
        $this->assertSame('Име на средното училиште / Emri i shkollës së mesme', $exporter->headers($this->program, 'host')['host_school']);

        // No placeholder is left in any header of either format.
        foreach (DynamicsExporter::FORMATS as $format) {
            foreach ($exporter->headers($this->program, $format) as $label) {
                $this->assertDoesNotMatchRegularExpression('~:(staff|school)~', $label);
            }
        }
    }

    public function test_filters_title_row_and_validation(): void
    {
        $id = $this->program->id;

        $sheet = $this->sheet($this->get("/api/edu-programs/{$id}/dynamics.xlsx?status=cancelled")->assertOk());
        $this->assertSame('4', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('=SUM(E2:E2)', $sheet->getCell('E3')->getValue());

        $sheet = $this->sheet($this->get("/api/edu-programs/{$id}/dynamics.xlsx?status[]=planned&status[]=cancelled")->assertOk());
        $this->assertSame(6, $sheet->getHighestDataRow());

        // Explicit group ids win over the default status filter.
        $ids = http_build_query(['group_ids' => [$this->groups[4]->id, $this->groups[2]->id]]);
        $sheet = $this->sheet($this->get("/api/edu-programs/{$id}/dynamics.xlsx?{$ids}")->assertOk());
        $this->assertSame(['2', '4'], [(string) $sheet->getCell('A2')->getValue(), (string) $sheet->getCell('A3')->getValue()]);

        // Title row: the header moves down, so do the frozen pane and the formula.
        $sheet = $this->sheet($this->get("/api/edu-programs/{$id}/dynamics.xlsx?with_title=1")->assertOk());
        $this->assertSame('Обука за стручни соработници во основните училишта — јуни 2026', $sheet->getCell('A1')->getValue());
        $this->assertArrayHasKey('A1:I1', $sheet->getMergeCells());
        $this->assertSame('Ред. Бр', $sheet->getCell('A2')->getValue());
        $this->assertSame('A3', $sheet->getFreezePane());
        $this->assertSame('=SUM(E3:E5)', $sheet->getCell('E6')->getValue());

        // An empty program still gives headers and a zero total.
        $empty = $this->eduProgram(['title' => 'Празна']);
        $sheet = $this->sheet($this->get("/api/edu-programs/{$empty->id}/dynamics.xlsx")->assertOk());
        $this->assertSame('Ред. Бр', $sheet->getCell('A1')->getValue());
        $this->assertEquals(0, $sheet->getCell('E2')->getValue());

        $other = $this->eduProgram(['title' => 'Друга']);
        $this->getJson("/api/edu-programs/{$other->id}/dynamics.xlsx?group_ids[]={$this->groups[1]->id}")->assertStatus(422)->assertJsonValidationErrors('group_ids');
        $this->getJson("/api/edu-programs/{$id}/dynamics.xlsx?format=pdf")->assertStatus(422)->assertJsonValidationErrors('format');
        $this->getJson("/api/edu-programs/{$id}/dynamics.xlsx?status=closed")->assertStatus(422);
        $this->getJson('/api/edu-programs/999999/dynamics.xlsx')->assertNotFound();
    }

    public function test_career_counselors_header(): void
    {
        $this->program->update(['staff_type' => 'career_counselors']);
        $sheet = $this->sheet($this->get("/api/edu-programs/{$this->program->id}/dynamics.xlsx")->assertOk());
        $this->assertSame('Присутни кариерни советници од училиште', $sheet->getCell('C1')->getValue());
    }
}
