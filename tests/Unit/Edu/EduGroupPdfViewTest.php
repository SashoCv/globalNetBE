<?php

namespace Tests\Unit\Edu;

use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class EduGroupPdfViewTest extends TestCase
{
    private function personsGroup(array $overrides = []): array
    {
        return array_merge([
            'program_title' => 'Обука за стручни соработници во основните училишта',
            'topic' => 'Кариерно советување',
            'number' => 3,
            'staff_label' => 'Стручни соработници',
            'location' => 'ООУ „Панко Брашнаров“',
            'address' => 'ул. Благој Ѓорев бб',
            'city' => 'Велес',
            'language' => 'мк',
            'trainer' => 'Катерина Димеска',
            'dates' => '01-02.06.2026',
            'time' => '8.30-13.30 (два дена)',
            'participants' => [
                ['name' => 'Ана Петровска', 'school' => 'ООУ „Панко Брашнаров“', 'municipality' => 'Велес', 'email' => 'ana@example.mk', 'phone' => '070123456'],
                ['name' => 'Бесник Алиу', 'school' => 'ООУ „Тодор Јанев“', 'municipality' => 'Чашка', 'email' => null, 'phone' => '071000000'],
            ],
            'signature_days' => ['01.06', '02.06'],
        ], $overrides);
    }

    private function countsGroup(array $overrides = []): array
    {
        return array_merge([
            'program_title' => 'Обука за кариерни советници',
            'number' => 7,
            'location' => 'СОУ „Гостивар“',
            'city' => 'Гостивар',
            'language' => 'алб',
            'trainer' => 'Lumturije Mehmedi Sinani',
            'dates' => '30.06-01.07.2026',
            'time' => '9.00-13.00 (два дена)',
            'schools' => [
                ['school' => 'СОУ „Гостивар“', 'municipality' => 'Гостивар', 'count' => 3],
                ['school' => 'СОМУ „Гостивар“', 'municipality' => 'Гостивар', 'count' => 2, 'count_label' => '1+1т'],
            ],
        ], $overrides);
    }

    public function test_persons_mode_renders_header_participants_and_signature_columns(): void
    {
        $html = view('pdf.edu-group', ['group' => $this->personsGroup(), 'printed_at' => '15.05.2026'])->render();

        foreach ([
            'Обука за стручни соработници во основните училишта',
            'Тема: Кариерно советување',
            'Група 3',
            'Стручни соработници',
            'ООУ „Панко Брашнаров“, ул. Благој Ѓорев бб',
            'Велес',
            'Катерина Димеска',
            '01-02.06.2026, 8.30-13.30 (два дена)',
            'Ана Петровска',
            'ana@example.mk',
            'Бесник Алиу',
            'Чашка',
            'Потпис 01.06',
            'Потпис 02.06',
            'Вкупно учесници: 2',
            'Отпечатено: 15.05.2026',
            'DejaVu Sans',
        ] as $needle) {
            $this->assertStringContainsString(e($needle), $html, $needle);
        }
        $this->assertStringNotContainsString('Број на учесници', $html);
    }

    public function test_persons_mode_without_signatures(): void
    {
        $html = view('pdf.edu-group', ['group' => $this->personsGroup(['signature_days' => []])])->render();

        $this->assertStringNotContainsString('Потпис', $html);
        $this->assertStringContainsString('Отпечатено: ' . now()->format('d.m.Y'), $html);
    }

    public function test_counts_mode_renders_schools_total_and_blank_rows(): void
    {
        $html = view('pdf.edu-group', ['group' => $this->countsGroup([
            'blank_rows' => 5,
            'signature_days' => ['30.06', '01.07'],
        ])])->render();

        foreach (['Група 7', 'Lumturije Mehmedi Sinani', 'СОМУ „Гостивар“', 'Број на учесници', '1+1т', 'Листа на учесници', 'Потпис 30.06'] as $needle) {
            $this->assertStringContainsString(e($needle), $html, $needle);
        }
        $this->assertMatchesRegularExpression('/<td colspan="3">Вкупно<\/td>\s*<td class="count">5<\/td>/u', $html);
        $this->assertSame(5, substr_count($html, '<tr class="blank">'));
        $this->assertSame(1, substr_count($html, 'Име и презиме'));
    }

    public function test_counts_mode_is_the_default_without_participants(): void
    {
        $html = view('pdf.edu-group', ['group' => $this->countsGroup()])->render();

        $this->assertStringContainsString('Број на учесници', $html);
        $this->assertStringNotContainsString('Име и презиме', $html);
        $this->assertStringNotContainsString('Листа на учесници', $html);
    }

    public function test_values_are_escaped(): void
    {
        $html = view('pdf.edu-group', ['group' => $this->personsGroup([
            'participants' => [['name' => '<script>x</script>']],
        ])])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_groups_document_has_one_page_per_group(): void
    {
        $html = view('pdf.edu-groups', ['groups' => [
            $this->personsGroup(),
            $this->countsGroup(),
            $this->personsGroup(['number' => 9]),
        ]])->render();

        foreach (['Група 3', 'Група 7', 'Група 9'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertSame(3, substr_count($html, '<div class="group'));
        $this->assertSame(2, substr_count($html, 'group--break"'));
        $this->assertSame(1, substr_count($html, 'class="footer"'));
    }

    public function test_groups_document_without_groups(): void
    {
        $html = view('pdf.edu-groups', ['groups' => []])->render();

        $this->assertStringContainsString('Нема групи.', $html);
    }

    public function test_dompdf_renders_both_documents(): void
    {
        $single = Pdf::loadView('pdf.edu-group', ['group' => $this->personsGroup()])->setPaper('a4')->output();
        $this->assertStringStartsWith('%PDF', $single);

        $many = Pdf::loadView('pdf.edu-groups', ['groups' => [
            $this->personsGroup(),
            $this->countsGroup(['blank_rows' => 3]),
        ]])->setPaper('a4')->output();
        $this->assertStringStartsWith('%PDF', $many);
    }
}
