<?php

namespace App\Services\Edu;

use App\Support\Edu\GroupDocumentLabels;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Writer\WriterInterface;

/**
 * Обуки: the group documents of step 11 as Word (.docx, decision 5), from
 * the same GroupDocumentBuilder arrays as resources/views/pdf/edu-group*.
 * One A4 portrait section (page) per group, with its own footer; the list
 * tables repeat their header row on every page and end with a total row.
 */
class GroupDocxRenderer
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const FONT = 'Arial';
    private const NAVY = '0B3F6B';
    private const GREY = '64748B';
    private const BORDER = '94A3B8';
    private const ALT = 'F1F5F9';
    private const TOTAL = 'E2E8F0';

    /** Cells wrap their text (PHPWord's default is noWrap). */
    private const CELL = ['noWrap' => false];

    /** Usable width of the page: A4 (210mm) less 11mm margins on each side. */
    private const WIDTH_MM = 188;

    /**
     * @param  list<array<string, mixed>>  $groups  GroupDocumentBuilder::build() / buildMany() arrays
     */
    public function document(array $groups, ?string $title = null, ?string $printedAt = null): PhpWord
    {
        // School and place names may contain "&" or "<".
        Settings::setOutputEscapingEnabled(true);

        $groups = array_values($groups);
        $labels = GroupDocumentBuilder::documentLabels($groups);
        $printedAt ??= now()->format('d.m.Y');

        $word = new PhpWord();
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(9);
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0]);
        $word->getSettings()->setThemeFontLang(new Language('mk-MK'));
        $title ??= $groups[0]['program_title'] ?? 'Обука';
        $word->getDocInfo()->setTitle(count($groups) === 1
            ? $title . ' — ' . GroupDocumentLabels::number($groups[0]['labels']['group'] ?? $labels['group'], $groups[0]['number'] ?? '')
            : $title . ' — ' . $labels['groups']);

        if ($groups === []) {
            $section = $this->section($word, $labels, $printedAt);
            $section->addText($labels['no_groups'], ['italic' => true, 'color' => self::GREY]);
        }
        foreach ($groups as $group) {
            $this->group($word, $group, $printedAt);
        }

        return $word;
    }

    public function writer(PhpWord $word): WriterInterface
    {
        return IOFactory::createWriter($word, 'Word2007');
    }

    private function section(PhpWord $word, array $labels, string $printedAt): Section
    {
        // A4 portrait in whole twips (PHPWord's 'A4' paper gives fractions Word may reject).
        $section = $word->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => 11906,
            'pageSizeH' => 16838,
            'marginTop' => $this->mm(12),
            'marginBottom' => $this->mm(16),
            'marginLeft' => $this->mm(11),
            'marginRight' => $this->mm(11),
            'footerHeight' => $this->mm(6),
            'breakType' => 'nextPage',
        ]);

        $footer = $section->addFooter();
        $table = $footer->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT, 'borderTopSize' => 4, 'borderTopColor' => 'CBD5E1']);
        $table->addRow();
        $font = ['size' => 7.5, 'color' => self::GREY];
        $table->addCell($this->mm(self::WIDTH_MM / 2), self::CELL)->addText($labels['printed'] . ': ' . $printedAt, $font);
        $table->addCell($this->mm(self::WIDTH_MM / 2), self::CELL)
            ->addPreserveText($labels['page'] . ' {PAGE}', $font, ['alignment' => Jc::END]);

        return $section;
    }

    private function group(PhpWord $word, array $group, string $printedAt): void
    {
        $L = $group['labels'] ?? GroupDocumentLabels::make(false);
        $bi = !empty($group['bilingual']);
        $section = $this->section($word, $L, $printedAt);

        $participants = array_values($group['participants'] ?? []);
        $schools = array_values($group['schools'] ?? []);
        $mode = $group['mode'] ?? ($participants !== [] ? 'persons' : 'counts');
        $days = array_values($group['signature_days'] ?? []);
        $blankRows = max(0, (int) ($group['blank_rows'] ?? 0));
        $total = $group['total'] ?? ($mode === 'persons'
            ? count($participants)
            : array_sum(array_map(fn ($s) => (int) ($s['count'] ?? 0), $schools)));

        // Head: program, topic, "Група N", staff, a navy rule under it.
        $section->addText((string) ($group['program_title'] ?? ''), ['bold' => true, 'size' => 11, 'color' => self::NAVY]);
        if (filled($group['topic'] ?? null)) {
            $section->addText($L['topic'] . ': ' . $group['topic'], ['size' => 8.5, 'color' => '475569'], ['spaceBefore' => 40]);
        }
        $last = filled($group['staff_label'] ?? null) ? null : ['borderBottomSize' => 12, 'borderBottomColor' => self::NAVY, 'spaceAfter' => 160];
        $section->addText(
            GroupDocumentLabels::number($L['group'], $group['number'] ?? ''),
            ['bold' => true, 'size' => 16],
            ['spaceBefore' => 100] + ($last ?? []),
        );
        if ($last === null) {
            $section->addText((string) $group['staff_label'], ['size' => 9, 'color' => '475569'],
                ['borderBottomSize' => 12, 'borderBottomColor' => self::NAVY, 'spaceAfter' => 160]);
        }

        $this->meta($section, $group, $L);

        if ($mode === 'persons') {
            $this->personsTable($section, $L, $days, $participants, $total, $bi);

            return;
        }

        $this->countsTable($section, $L, $schools, $total, $bi);
        if ($blankRows > 0) {
            $section->addText($L['participants_list'], ['bold' => true, 'size' => 9.5, 'color' => self::NAVY], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true]);
            $this->personsTable($section, $L, $days, array_fill(0, $blankRows, null), null, $bi);
        }
    }

    private function meta(Section $section, array $group, array $L): void
    {
        $join = fn (array $parts) => implode(', ', array_filter($parts, fn ($v) => filled($v)));
        $rows = array_filter([
            $L['place'] => $join([$group['location'] ?? null, $group['address'] ?? null]),
            $L['city'] => $group['city'] ?? null,
            $L['language'] => $group['language'] ?? null,
            $L['trainer'] => $group['trainer'] ?? null,
            $L['when'] => $join([$group['dates'] ?? null, $group['time'] ?? null]),
        ], fn ($v) => filled($v));
        if ($rows === []) {
            return;
        }

        $labelWidth = empty($group['bilingual']) ? 30 : 48;
        $table = $section->addTable(['cellMarginTop' => 20, 'cellMarginBottom' => 20, 'cellMarginRight' => 80, 'layout' => 'fixed']);
        foreach ($rows as $label => $value) {
            $table->addRow();
            $table->addCell($this->mm($labelWidth), self::CELL)->addText($label . ':', ['color' => self::GREY]);
            $table->addCell($this->mm(self::WIDTH_MM - $labelWidth), self::CELL)->addText((string) $value, ['bold' => true]);
        }
        $section->addText('', [], ['spaceAfter' => 120]);
    }

    /**
     * The participant list, or with $rows of nulls the empty numbered rows
     * of the counts mode; $total null = no total row.
     *
     * @param  list<array<string, mixed>|null>  $rows
     */
    private function personsTable(Section $section, array $L, array $days, array $rows, ?int $total, bool $bilingual = false): void
    {
        // Bilingual headers ("Потпис / Nënshkrimi 01.06") need a little more room.
        $no = $bilingual ? 11 : 8;
        $sign = count($days) > 4 ? 13 : ($bilingual ? 19 : 16);
        $contact = count($days) > 3 ? 30 : 38;
        $flex = max(40, self::WIDTH_MM - $no - 32 - $contact - $sign * count($days));
        $widths = [$no, 32, (int) round($flex * 0.62), (int) round($flex * 0.38), $contact, ...array_fill(0, count($days), $sign)];

        $table = $this->listTable($section);
        $this->headerRow($table, $widths, [
            $L['no'], $L['name'], $L['school'], $L['municipality'], $L['contact'],
            ...array_map(fn ($d) => $L['signature'] . ' ' . $d, $days),
        ]);

        $blank = $rows !== [] && $rows[0] === null;
        if ($rows === []) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($this->mm(array_sum($widths)), ['gridSpan' => count($widths)] + self::CELL)
                ->addText($L['no_participants'], ['italic' => true, 'color' => self::GREY]);
        }
        foreach ($rows as $i => $p) {
            $table->addRow($blank ? $this->mm(7.5) : null, ['cantSplit' => true, 'exactHeight' => $blank]);
            $shade = (!$blank && $i % 2 ? ['bgColor' => self::ALT] : []) + self::CELL;
            $table->addCell($this->mm($widths[0]), $shade + ['valign' => 'center'])->addText((string) ($i + 1), [], ['alignment' => Jc::CENTER]);
            $table->addCell($this->mm($widths[1]), $shade)->addText((string) ($p['name'] ?? ''));
            $table->addCell($this->mm($widths[2]), $shade)->addText((string) ($p['school'] ?? ''));
            $table->addCell($this->mm($widths[3]), $shade)->addText((string) ($p['municipality'] ?? ''));
            $cell = $table->addCell($this->mm($widths[4]), $shade);
            $cell->addText((string) ($p['email'] ?? ''));
            if (filled($p['phone'] ?? null)) {
                $cell->addText((string) $p['phone'], ['size' => 8, 'color' => '475569']);
            }
            foreach ($days as $k => $day) {
                $table->addCell($this->mm($widths[5 + $k]), $shade);
            }
        }

        if ($total !== null) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($this->mm(array_sum($widths)), ['gridSpan' => count($widths), 'bgColor' => self::TOTAL] + self::CELL)
                ->addText($L['total_participants'] . ': ' . $total, ['bold' => true]);
        }
    }

    /** @param  list<array{school?: string, municipality?: ?string, count?: int, count_label?: ?string}>  $schools */
    private function countsTable(Section $section, array $L, array $schools, int $total, bool $bilingual = false): void
    {
        $widths = $bilingual ? [11, 105, 42, 30] : [8, 112, 42, 26];
        $table = $this->listTable($section);
        $this->headerRow($table, $widths, [$L['no'], $L['school'], $L['municipality'], $L['count']]);

        if ($schools === []) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($this->mm(array_sum($widths)), ['gridSpan' => 4] + self::CELL)
                ->addText($L['no_schools'], ['italic' => true, 'color' => self::GREY]);
        }
        foreach ($schools as $i => $s) {
            $table->addRow(null, ['cantSplit' => true]);
            $shade = ($i % 2 ? ['bgColor' => self::ALT] : []) + self::CELL;
            $table->addCell($this->mm($widths[0]), $shade)->addText((string) ($i + 1), [], ['alignment' => Jc::CENTER]);
            $table->addCell($this->mm($widths[1]), $shade)->addText((string) ($s['school'] ?? ''));
            $table->addCell($this->mm($widths[2]), $shade)->addText((string) ($s['municipality'] ?? ''));
            $table->addCell($this->mm($widths[3]), $shade)
                ->addText((string) ($s['count_label'] ?? ($s['count'] ?? 0)), [], ['alignment' => Jc::CENTER]);
        }

        $table->addRow(null, ['cantSplit' => true]);
        $bold = ['bold' => true];
        $table->addCell($this->mm($widths[0] + $widths[1] + $widths[2]), ['gridSpan' => 3, 'bgColor' => self::TOTAL] + self::CELL)->addText($L['total'], $bold);
        $table->addCell($this->mm($widths[3]), ['bgColor' => self::TOTAL] + self::CELL)->addText((string) $total, $bold, ['alignment' => Jc::CENTER]);
    }

    private function listTable(Section $section): Table
    {
        return $section->addTable([
            'borderSize' => 6,
            'borderColor' => self::BORDER,
            'cellMarginTop' => 40,
            'cellMarginBottom' => 40,
            'cellMarginLeft' => 60,
            'cellMarginRight' => 60,
            'layout' => 'fixed',
            'width' => $this->mm(self::WIDTH_MM),
            'unit' => TblWidth::TWIP,
        ]);
    }

    /** Navy header row, repeated at the top of every page the table runs over. */
    private function headerRow(Table $table, array $widths, array $titles): void
    {
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($titles as $i => $title) {
            $table->addCell($this->mm($widths[$i]), ['bgColor' => self::NAVY, 'valign' => 'center'] + self::CELL)
                ->addText($title, ['bold' => true, 'size' => 7.5, 'color' => 'FFFFFF']);
        }
    }

    private function mm(int|float $mm): int
    {
        return (int) round($mm * 1440 / 25.4);
    }
}
