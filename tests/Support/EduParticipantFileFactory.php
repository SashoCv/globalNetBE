<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds participant lists and per-school count sheets in code (made-up
 * names), for UploadedFile::fake()->createWithContent().
 */
class EduParticipantFileFactory
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     * @param  list<string>  $titleRows  text put in column A above the header
     * @param  array<string, array{0: list<string>, 1: list<list<mixed>>}>  $otherSheets  sheets placed before this one
     */
    public static function xlsx(array $header, array $rows, string $sheet = 'Учесници', array $titleRows = [], array $otherSheets = []): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($otherSheets as $name => [$otherHeader, $otherRows]) {
            self::sheet($book, $name, $otherHeader, $otherRows, []);
        }
        self::sheet($book, $sheet, $header, $rows, $titleRows);

        $path = tempnam(sys_get_temp_dir(), 'edupart') . '.xlsx';
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public static function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private static function sheet(Spreadsheet $book, string $name, array $header, array $rows, array $titleRows): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($name);
        $r = 1;
        foreach ($titleRows as $text) {
            $sheet->setCellValue("A{$r}", $text);
            $r++;
        }
        foreach ([$header, ...$rows] as $row) {
            foreach (array_values($row) as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = chr(ord('A') + $c) . $r;
                is_int($value)
                    ? $sheet->setCellValue($cell, $value)
                    : $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
            }
            $r++;
        }
    }
}
