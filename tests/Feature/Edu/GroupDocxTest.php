<?php

namespace Tests\Feature\Edu;

use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Services\Edu\GroupDocxRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\EduOutputFixtures;
use Tests\TestCase;
use ZipArchive;

/** Word (.docx) group documents (decision 5): same data and labels as the PDFs. */
class GroupDocxTest extends TestCase
{
    use EduOutputFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-15 10:00:00');
        $this->buildOutputFixtures();
    }

    /** word/document.xml of a .docx response, its text runs joined. */
    private function documentXml(TestResponse $response): string
    {
        $this->assertSame(GroupDocxRenderer::MIME, $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("PK", $content);

        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $footers = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/footer')) {
                $footers .= $zip->getFromName($name);
            }
        }
        $zip->close();
        unlink($path);
        $this->assertIsString($xml);

        return $xml . $footers;
    }

    public function test_group_docx_endpoint(): void
    {
        $response = $this->get("/api/edu-groups/{$this->groups[2]->id}/document.docx")->assertOk();
        $this->assertStringContainsString('grupa-2-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista.docx', $response->headers->get('Content-Disposition'));
        $xml = $this->documentXml($response);

        foreach ([
            'Обука за стручни соработници во основните училишта',
            'Група 2',
            'Стручни соработници',
            'Македонски',
            'Катерина Димеска',
            '01-02.06.2026, 8.30-13.30 (два дена)',
            '1+1т',
            'Број на учесници',
            'Вкупно',
            'Листа на учесници',
            'Потпис 01.06',
            'Потпис 02.06',
            'Отпечатено: 15.05.2026',
            'Страна',
            e($this->schools['panko']->fresh()->display_name),
        ] as $needle) {
            $this->assertStringContainsString($needle, $xml, $needle);
        }
        $this->assertStringNotContainsString('Trajner', $xml);
        // Header rows repeat on every page; A4 portrait.
        $this->assertStringContainsString('<w:tblHeader', $xml);
        $this->assertStringContainsString('w:w="11906" w:h="16838"', $xml);
        // Whole twips only: Word rejects fractional measures.
        $this->assertDoesNotMatchRegularExpression('/w:[a-z]+="\d+\.\d+"/i', $xml);

        $xml = $this->documentXml($this->get("/api/edu-groups/{$this->groups[2]->id}/document.docx?signatures=0&blank_rows=0")->assertOk());
        $this->assertStringNotContainsString('Потпис', $xml);
        $this->assertStringNotContainsString('Листа на учесници', $xml);

        $this->getJson("/api/edu-groups/{$this->groups[2]->id}/document.docx?signatures=maybe")->assertStatus(422);
        $this->getJson('/api/edu-groups/999999/document.docx')->assertNotFound();
    }

    public function test_albanian_group_docx_is_bilingual(): void
    {
        $xml = $this->documentXml($this->get("/api/edu-groups/{$this->groups[1]->id}/document.docx")->assertOk());
        foreach ([
            'Група 1 / Grupi 1',
            'Обучувач / Trajner:',
            'Место / Vendi:',
            'Јазик / Gjuha:',
            'Албански / Shqip',
            'Lumturije Mehmedi',
            'Вкупно / Gjithsej',
            'Листа на учесници / Lista e pjesëmarrësve',
            'Потпис / Nënshkrimi 30.06',
            'Отпечатено / Shtypur më: 15.05.2026',
            'Страна / Faqja',
        ] as $needle) {
            $this->assertStringContainsString($needle, $xml, $needle);
        }

        $xml = $this->documentXml($this->get("/api/edu-groups/{$this->groups[1]->id}/document.docx?bilingual=0")->assertOk());
        $this->assertStringContainsString('Обучувач:', $xml);
        $this->assertStringNotContainsString('Trajner', $xml);

        $xml = $this->documentXml($this->get("/api/edu-groups/{$this->groups[2]->id}/document.docx?bilingual=1")->assertOk());
        $this->assertStringContainsString('Обучувач / Trajner:', $xml);
    }

    public function test_persons_mode_docx_lists_participants(): void
    {
        $this->program->update(['participant_mode' => EduProgram::MODE_PERSONS]);
        foreach ([['todor', 'Ана Петровска', 'ana@example.mk', '070123456'], ['panko', 'Бојан & Илиевски', null, null]] as [$school, $name, $email, $phone]) {
            EduParticipant::create([
                'program_id' => $this->program->id, 'school_id' => $this->schools[$school]->id, 'language' => 'mk',
                'full_name' => $name, 'name_key' => mb_strtolower($name), 'email' => $email, 'phone' => $phone,
                'group_id' => $this->groups[2]->id,
            ]);
        }

        $xml = $this->documentXml($this->get("/api/edu-groups/{$this->groups[2]->id}/document.docx")->assertOk());
        foreach (['Ана Петровска', 'Бојан &amp; Илиевски', 'ana@example.mk', '070123456', 'Име и презиме', 'Вкупно учесници: 2', 'Потпис 01.06'] as $needle) {
            $this->assertStringContainsString($needle, $xml, $needle);
        }
    }

    public function test_groups_docx_endpoint(): void
    {
        $id = $this->program->id;
        $response = $this->get("/api/edu-programs/{$id}/groups/documents.docx")->assertOk();
        $this->assertStringContainsString('grupi-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista-2026-05-15.docx', $response->headers->get('Content-Disposition'));
        $xml = $this->documentXml($response);
        foreach (['Група 1 / Grupi 1', 'Група 2', 'Група 3 / Grupi 3'] as $needle) {
            $this->assertStringContainsString($needle, $xml);
        }
        $this->assertStringNotContainsString('Група 4', $xml);
        // One section (page) per group.
        $this->assertSame(3, substr_count($xml, '<w:sectPr'));

        $ids = http_build_query(['group_ids' => [$this->groups[2]->id], 'bilingual' => 1]);
        $xml = $this->documentXml($this->get("/api/edu-programs/{$id}/groups/documents.docx?{$ids}")->assertOk());
        $this->assertStringContainsString('Група 2 / Grupi 2', $xml);
        $this->assertSame(1, substr_count($xml, '<w:sectPr'));

        $xml = $this->documentXml($this->get("/api/edu-programs/{$id}/groups/documents.docx?status=cancelled")->assertOk());
        $this->assertStringContainsString('Група 4', $xml);

        $other = $this->eduProgram(['title' => 'Друга']);
        $this->getJson("/api/edu-programs/{$other->id}/groups/documents.docx?{$ids}")->assertStatus(422);
        $xml = $this->documentXml($this->get("/api/edu-programs/{$other->id}/groups/documents.docx")->assertOk());
        $this->assertStringContainsString('Нема групи.', $xml);
    }
}
