<?php

namespace Tests\Feature\Edu;

use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Services\Edu\EduGroupOutput;
use App\Services\Edu\GroupDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduOutputFixtures;
use Tests\TestCase;

/**
 * dompdf has no fake(): the endpoints are checked for a PDF, the content by
 * rendering the same view with the data the controller builds.
 */
class GroupPdfTest extends TestCase
{
    use EduOutputFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-15 10:00:00');
        $this->buildOutputFixtures();
    }

    private function build(int $number, array $options = []): array
    {
        return app(GroupDocumentBuilder::class)->build($this->groups[$number]->fresh(), $this->program->fresh(), $options);
    }

    private function html(array $data): string
    {
        return view('pdf.edu-group', ['group' => $data, 'printed_at' => '15.05.2026'])->render();
    }

    public function test_group_pdf_endpoint(): void
    {
        $response = $this->get("/api/edu-groups/{$this->groups[2]->id}/document.pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('grupa-2-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->get("/api/edu-groups/{$this->groups[2]->id}/document.pdf?signatures=0&blank_rows=0")->assertOk();
        $this->getJson("/api/edu-groups/{$this->groups[2]->id}/document.pdf?signatures=maybe")->assertStatus(422);
        $this->getJson('/api/edu-groups/999999/document.pdf')->assertNotFound();
    }

    public function test_counts_mode_document(): void
    {
        $data = $this->build(2);
        $this->assertSame('counts', $data['mode']);
        $this->assertSame([
            ['school' => $this->schools['panko']->fresh()->display_name, 'municipality' => 'Велес', 'count' => 2, 'count_label' => '1+1т'],
            ['school' => $this->schools['todor']->fresh()->display_name, 'municipality' => 'Чашка', 'count' => 3, 'count_label' => '3'],
        ], $data['schools']);
        $this->assertSame(['01.06', '02.06'], $data['signature_days']);
        $this->assertSame(5, $data['blank_rows']);
        $this->assertSame(5, $data['total']);

        $html = $this->html($data);
        foreach ([
            'Обука за стручни соработници во основните училишта',
            'Група 2',
            'Стручни соработници',
            $this->schools['panko']->fresh()->display_name,
            'Велес',
            'Македонски',
            'Катерина Димеска',
            '01-02.06.2026, 8.30-13.30 (два дена)',
            '1+1т',
            'Потпис 01.06',
            'Потпис 02.06',
            'Листа на учесници',
        ] as $needle) {
            $this->assertStringContainsString(e($needle), $html, $needle);
        }
        $this->assertSame(5, substr_count($html, 'class="blank"'));

        $data = $this->build(2, ['signatures' => false, 'blank_rows' => false]);
        $this->assertSame([], $data['signature_days']);
        $this->assertSame(0, $data['blank_rows']);
        $html = $this->html($data);
        $this->assertStringNotContainsString('Потпис', $html);
        $this->assertStringNotContainsString('class="blank"', $html);
    }

    public function test_albanian_group_prints_latin_trainer_and_place(): void
    {
        $data = $this->build(1);
        $this->assertSame('Lumturije Mehmedi', $data['trainer']);
        $this->assertSame('Хотел Турист', $data['location']);
        $this->assertSame('ул. Гиго Михајловски 1', $data['address']);
        $this->assertSame('Скопје', $data['city']);
        // Albanian group: bilingual by default (decision 4), the language included.
        $this->assertTrue($data['bilingual']);
        $this->assertSame('Албански / Shqip', $data['language']);
        $this->assertSame(['30.06', '01.07'], $data['signature_days']);
        $this->assertStringContainsString('Lumturije Mehmedi', $this->html($data));

        // No schedule yet: one signature column per default day.
        $data = $this->build(3);
        $this->assertSame(['1. ден / Dita 1', '2. ден / Dita 2'], $data['signature_days']);
        $this->assertSame(['1. ден', '2. ден'], $this->build(3, ['bilingual' => false])['signature_days']);
        $this->assertSame('ул. „Букурешка“ бб / Rr. „Bukureshka“ pn.', $data['address']);
        $this->assertNull($data['trainer']);
        $this->assertSame('', $data['dates']);
    }

    public function test_albanian_group_has_bilingual_labels(): void
    {
        $data = $this->build(1);
        $html = $this->html($data);
        foreach ([
            'Група 1 / Grupi 1',
            'Место / Vendi:',
            'Град / Qyteti:',
            'Јазик / Gjuha:',
            'Обучувач / Trajner:',
            'Датум и време / Data dhe ora:',
            'Ред. бр. / Nr. rend.',
            'Училиште / Shkolla',
            'Општина / Komuna',
            'Број на учесници / Numri i pjesëmarrësve',
            'Вкупно / Gjithsej',
            'Листа на учесници / Lista e pjesëmarrësve',
            'Име и презиме / Emri dhe mbiemri',
            'E-mail / Телефон / Telefoni',
            'Потпис / Nënshkrimi 30.06',
            'Стручни соработници / Bashkëpunëtorë profesionalë',
            'Отпечатено / Shtypur më: 15.05.2026',
            'Страна / Faqja',
            // Values stay as they are.
            'Хотел Турист',
            'Lumturije Mehmedi',
        ] as $needle) {
            $this->assertStringContainsString(e($needle), $html, $needle);
        }

        // Forced off: Macedonian only; a Macedonian group stays Macedonian unless forced on.
        $html = $this->html($this->build(1, ['bilingual' => false]));
        $this->assertStringContainsString('Обучувач:', $html);
        $this->assertStringNotContainsString('Trajner', $html);
        $this->assertStringContainsString('Албански', $html);
        $this->assertStringNotContainsString('Shqip', $html);

        $mk = $this->build(2);
        $this->assertFalse($mk['bilingual']);
        $this->assertStringNotContainsString('Trajner', $this->html($mk));
        $this->assertStringContainsString(e('Обучувач / Trajner'), $this->html($this->build(2, ['bilingual' => true])));

        $this->get("/api/edu-groups/{$this->groups[1]->id}/document.pdf?bilingual=0")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/edu-groups/{$this->groups[2]->id}/document.pdf?bilingual=1")->assertOk();
        $this->get("/api/edu-groups/{$this->groups[2]->id}/document.pdf?bilingual=auto")->assertOk();
        $this->getJson("/api/edu-groups/{$this->groups[2]->id}/document.pdf?bilingual=maybe")->assertStatus(422);
    }

    public function test_mixed_document_footer_is_bilingual_when_any_group_is(): void
    {
        $output = app(EduGroupOutput::class);
        $builder = app(GroupDocumentBuilder::class);
        $data = $builder->buildMany($output->groups($this->program), $this->program);
        $html = view('pdf.edu-groups', ['groups' => $data, 'printed_at' => '15.05.2026'])->render();
        $this->assertStringContainsString('Група 2', $html);
        $this->assertStringNotContainsString('Група 2 / Grupi 2', $html);
        $this->assertStringContainsString('Група 1 / Grupi 1', $html);
        $this->assertStringContainsString(e('Отпечатено / Shtypur më: 15.05.2026'), $html);

        $mkOnly = $builder->buildMany($output->groups($this->program, [$this->groups[2]->id]), $this->program);
        $html = view('pdf.edu-groups', ['groups' => $mkOnly, 'printed_at' => '15.05.2026'])->render();
        $this->assertStringContainsString('Отпечатено: 15.05.2026', $html);
    }

    public function test_persons_mode_lists_participants(): void
    {
        $this->program->update(['participant_mode' => EduProgram::MODE_PERSONS]);
        $people = [
            ['todor', 'mk', 'Ана Петровска', 'ana@example.mk', '070123456'],
            ['panko', 'tr', 'Ayşe Yılmaz', null, null],
            ['panko', 'mk', 'Бојан Илиевски', 'bojan@example.mk', null],
        ];
        foreach ($people as [$school, $lang, $name, $email, $phone]) {
            EduParticipant::create([
                'program_id' => $this->program->id, 'school_id' => $this->schools[$school]->id, 'language' => $lang,
                'full_name' => $name, 'name_key' => mb_strtolower($name), 'email' => $email, 'phone' => $phone,
                'group_id' => $this->groups[2]->id,
            ]);
        }

        $data = $this->build(2);
        $this->assertSame('persons', $data['mode']);
        // Allocation order of the schools, then name.
        $this->assertSame(['Ayşe Yılmaz', 'Бојан Илиевски', 'Ана Петровска'], array_column($data['participants'], 'name'));
        $this->assertSame(['name' => 'Ана Петровска', 'school' => $this->schools['todor']->fresh()->display_name, 'municipality' => 'Чашка', 'email' => 'ana@example.mk', 'phone' => '070123456'], $data['participants'][2]);

        $html = $this->html($data);
        foreach (['Група 2', 'Ана Петровска', 'Ayşe Yılmaz', 'bojan@example.mk', '070123456', 'Потпис 01.06', 'Потпис 02.06', 'Вкупно учесници: 3'] as $needle) {
            $this->assertStringContainsString(e($needle), $html, $needle);
        }

        $this->get("/api/edu-groups/{$this->groups[2]->id}/document.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_groups_pdf_endpoint(): void
    {
        $id = $this->program->id;
        $response = $this->get("/api/edu-programs/{$id}/groups/documents.pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('grupi-obuka-za-strucni-sorabotnici-vo-osnovnite-ucilista-2026-05-15.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Same selection as the Excel: cancelled groups out by default, explicit ids in order of number.
        $output = app(EduGroupOutput::class);
        $this->assertSame([1, 2, 3], $output->groups($this->program)->pluck('number')->all());
        $this->assertSame([2, 4], $output->groups($this->program, [$this->groups[4]->id, $this->groups[2]->id])->pluck('number')->all());

        $data = app(GroupDocumentBuilder::class)->buildMany($output->groups($this->program), $this->program);
        $html = view('pdf.edu-groups', ['groups' => $data, 'title' => $this->program->title])->render();
        foreach (['Група 1', 'Група 2', 'Група 3'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('Група 4', $html);
        $this->assertSame(2, substr_count($html, 'class="group group--break"'));

        $ids = http_build_query(['group_ids' => [$this->groups[1]->id]]);
        $this->get("/api/edu-programs/{$id}/groups/documents.pdf?{$ids}")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $other = $this->eduProgram(['title' => 'Друга']);
        $this->getJson("/api/edu-programs/{$other->id}/groups/documents.pdf?{$ids}")->assertStatus(422);
        $this->get("/api/edu-programs/{$other->id}/groups/documents.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
