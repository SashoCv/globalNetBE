<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduMunicipality;
use App\Models\EduProgram;
use App\Models\EduSchool;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Обуки: the "Динамика" Excel (step 10), one row per group.
 *
 *   standard  as the client's "ПРИМЕР ДИНАМИКА" sheet: place, attending
 *             schools, city, count, language, trainer, dates, time;
 *   host      as "ДИНАМИКА КАРИЕРНИ": the host school's region, address and
 *             contacts, then the attending schools, language and count.
 *
 * Headers come from config('edu.dynamics_headers'); a total row sums the
 * participant counts with a formula.
 */
class DynamicsExporter
{
    public const FORMATS = ['standard', 'host'];

    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Column widths per header key. */
    private const WIDTHS = [
        'standard' => [
            'number' => 6, 'location' => 30, 'schools' => 80, 'city' => 14, 'participants' => 10,
            'language' => 8, 'trainer' => 24, 'dates' => 18, 'time' => 22,
        ],
        'host' => [
            'number' => 8, 'region' => 18, 'city' => 14, 'municipality' => 16, 'host_school' => 34,
            'address' => 30, 'phone' => 16, 'director' => 22, 'email' => 26, 'email_schools_mk' => 30,
            'schools' => 80, 'language' => 8, 'participants' => 10, 'comment' => 20,
        ],
    ];

    /** Columns written as numbers; everything else is text (keeps "071…" phones and "03.06.2026"). */
    private const NUMERIC = ['number', 'participants'];

    private const CENTERED = ['number', 'participants', 'language'];

    public function __construct(private EduGroupOutput $output)
    {
    }

    /** @return array<string, string> header key => label */
    public function headers(EduProgram $program, string $format): array
    {
        $this->assertFormat($format);
        $level = config("edu.dynamics_school_level.{$program->school_level}")
            ?? config('edu.dynamics_school_level.' . EduSchool::LEVEL_SECONDARY, []);
        $replace = [
            ':staff' => EduProgram::STAFF_PLURAL[$program->staff_type] ?? 'учесници',
            ':school_sq' => $level['sq'] ?? '',
            ':school' => $level['mk'] ?? '',
        ];

        // strtr tries the longest placeholder first (":school_sq" before ":school").
        return array_map(fn ($label) => strtr((string) $label, $replace), config("edu.dynamics_headers.{$format}", []));
    }

    /**
     * The sheet's data rows, keyed like headers().
     *
     * @param  Collection<int, EduGroup>  $groups  loaded with EduGroupOutput::RELATIONS
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $groups, string $format): array
    {
        $this->assertFormat($format);

        return $groups->values()->map(fn (EduGroup $g) => $format === 'host'
            ? $this->hostRow($g)
            : $this->standardRow($g))->all();
    }

    /** @param Collection<int, EduGroup> $groups */
    public function spreadsheet(EduProgram $program, Collection $groups, string $format = 'standard', bool $withTitle = false): Spreadsheet
    {
        $headers = $this->headers($program, $format);
        $rows = $this->rows($groups, $format);
        $keys = array_keys($headers);
        $col = fn (string $key) => Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1);
        $lastCol = Coordinate::stringFromColumnIndex(count($keys));

        $book = new Spreadsheet();
        $book->getProperties()->setTitle('Динамика — ' . $program->title)->setCreator('GlobalNet');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Динамика');
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $r = 1;
        if ($withTitle) {
            $title = trim($program->title . (filled($program->period_label) ? ' — ' . $program->period_label : ''));
            $sheet->setCellValueExplicit("A{$r}", $title, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(13);
            $sheet->getStyle("A{$r}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($r)->setRowHeight(34);
            $r++;
        }

        $headerRow = $r;
        foreach ($keys as $i => $key) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $headerRow, $headers[$key], DataType::TYPE_STRING);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
            'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $first = $headerRow + 1;
        $r = $first;
        foreach ($rows as $row) {
            foreach ($keys as $i => $key) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $r;
                $value = $row[$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (in_array($key, self::NUMERIC, true) && is_int($value)) {
                    $sheet->setCellValue($cell, $value);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
            $r++;
        }
        $last = $r - 1;

        // Total row: the label in the column before the participant count.
        $totalRow = $r;
        $countCol = $col('participants');
        $labelCol = Coordinate::stringFromColumnIndex(max(1, Coordinate::columnIndexFromString($countCol) - 1));
        $sheet->setCellValueExplicit("{$labelCol}{$totalRow}", 'Вкупно', DataType::TYPE_STRING);
        $sheet->setCellValue("{$countCol}{$totalRow}", $last >= $first ? "=SUM({$countCol}{$first}:{$countCol}{$last})" : 0);
        $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->getFont()->setBold(true);

        // Body: borders, wrapping, top alignment; a few centred columns.
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        if ($last >= $first) {
            $sheet->getStyle("A{$first}:{$lastCol}{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }
        foreach (self::CENTERED as $key) {
            if (in_array($key, $keys, true)) {
                $c = $col($key);
                $sheet->getStyle("{$c}{$first}:{$c}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        foreach ($keys as $i => $key) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(self::WIDTHS[$format][$key] ?? 16);
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $sheet->setSelectedCell('A' . ($headerRow + 1));

        return $book;
    }

    public function writer(Spreadsheet $book): Xlsx
    {
        return new Xlsx($book);
    }

    /** "dinamika-{slug of the title}-2026-06-01.xlsx"; host format adds "-domakini". */
    public function filename(EduProgram $program, string $format = 'standard'): string
    {
        $this->assertFormat($format);

        return 'dinamika-' . $this->output->slug($program) . ($format === 'host' ? '-domakini' : '') . '-' . now()->format('Y-m-d') . '.xlsx';
    }

    private function standardRow(EduGroup $group): array
    {
        $schedule = $this->output->schedule($group);

        return [
            'number' => (int) $group->number,
            'location' => $this->output->place($group),
            'schools' => $this->output->schoolsText($group),
            'city' => $this->output->city($group),
            'participants' => $this->output->total($group),
            'language' => $this->output->languageLabel($group->language),
            'trainer' => $this->output->trainerName($group),
            'dates' => $schedule['dates'],
            'time' => $schedule['time'],
        ];
    }

    /** ДИНАМИКА КАРИЕРНИ: the contact block is filled only when the place is a school. */
    private function hostRow(EduGroup $group): array
    {
        $school = $this->output->hostSchool($group);
        $municipality = $this->output->venueMunicipality($group);
        // Albanian groups get "Тетово/ Tetovë"; Skopje stays "Скопје" / "Град Скопје" as in the sample.
        $sq = $group->language === 'sq' && $municipality && !$municipality->part_of_skopje;

        return [
            'number' => (int) $group->number,
            'region' => $this->regionLabel($municipality),
            'city' => $this->bilingual($this->output->city($group), $sq ? $municipality->name_sq : null),
            'municipality' => $this->bilingual($municipality?->name, $sq ? $municipality->name_sq : null),
            'host_school' => $this->output->place($group),
            'address' => $this->output->address($group),
            'phone' => $school ? ($school->phone_raw ?: implode(' / ', $school->phones ?? [])) : null,
            'director' => $school ? trim(($school->director_name ?? '') . ' ' . ($school->director_contact ?? '')) : null,
            'email' => $school ? ($school->email_raw ?: implode(', ', $school->emails ?? [])) : null,
            'email_schools_mk' => $school?->schools_mk_email,
            'schools' => $this->output->schoolsText($group),
            'language' => $this->output->languageLabel($group->language),
            'participants' => $this->output->total($group),
            'comment' => $group->comment,
        ];
    }

    /** "Скопски" → "Скопски регион". */
    private function regionLabel(?EduMunicipality $municipality): ?string
    {
        $name = $municipality?->region?->name;
        if (!filled($name)) {
            return null;
        }

        return mb_stripos($name, 'регион') === false ? "{$name} регион" : $name;
    }

    /** "Тетово/ Tetovë" for Albanian groups. */
    private function bilingual(?string $name, ?string $nameSq): ?string
    {
        return filled($name) && filled($nameSq) && $nameSq !== $name ? "{$name}/ {$nameSq}" : $name;
    }

    private function assertFormat(string $format): void
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown dynamics format: {$format}");
        }
    }
}
