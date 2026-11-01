<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds a small Обуки master-data workbook in code, laid out like the
 * client's file (same sheet names, header rows and columns) and with the
 * same oddities: Latin look-alike letters, ,,quotes'', bilingual names,
 * extra text after a municipality, "d." director phones, empty languages,
 * schools that differ only by type prefix, and trainers spelled differently
 * across sheets. All names, phones and e-mails are made up.
 */
class EduWorkbookFactory
{
    /** Columns A..L of "АДРЕСИ И ТЕЛЕФОНИ СРЕДНИ" (row 1 is the header). */
    public array $secondary = [
        [1, 'Вардарски регион', "СОУ Гимназија „Тест Еден“ / SHMK Gjimnazi \"Test Nje''", 'ул. „Тестова“ бр. 12/3 / Rr. „Testova“ nr. 12/3', 'Велес', 'Велес', 'М/А', '043/111111', 'Петар Тестовски', 'Петар 070111111', 'prv.test@yahoo,com', 'sou-testeden-veles@schools.mk;'],
        [2, 'Полошки регион', 'СОУ „Тестивар“/ SHMK „Testivar\'\'', 'ул. „Прва“ бб', 'Гостивар / Gostivar', 'Гостивар / Gostivar', 'М/А/Т', '042/222222', '', 'Директор 070222222', 'testivar@gmail.com', 'sou-testivar-gostivar@schools.mk;'],
        [3, 'Полошки регион', 'СОМУ „Тестивар“/ SHMKM „Testivar\'\'', 'ул. „Втора“ 7а', 'Гостивар / Gostivar', 'Гостивар / Gostivar', 'М/А/Т', '75235637', '', '', 'somu.testivar@gmail.com', 'sou-testivar-gostivar@schools.mk;'],
        [4, 'Југоисточен регион', 'СОУ „Тест Сусинов“', 'кеј Тест бр. 8 Радовиш', 'Радовиш  ', 'Радовиш  Тест 070-000-000', 'М/Т', '032/333333', '', '', 'susinov.test@yahoo.com', 'sou-testsusinov-strumica@schools.mk;'],
        [5, 'Скопски регион', 'СEПУГС  „Тест Јовков“/ SHMEJQSH Test Jovkov', 'ул. Тест бр. 1', 'Скопје', 'Бутел/Butel', 'М/А', '02/2444444', '', '', 'jovkov.test@gmail.com', 'sepugs-testjovkov-skopje@schools.mk'],
        [6, 'Скопски регион', 'СУГС „Тест Марку“/SHMQSH „Test Marku“', 'ул. Тест бр. 2', 'Скопје', 'Град Скопје', 'А', '02/2555555', '', '', 'marku.test@gmail.com', 'sugs-testmarku-skopje@schools.mk'],
        [7, 'Пелагониски регион', 'СОУ „Св. Тест Охридски“', 'ул. „Партизанска“ 22', 'Македонски Брод', 'Македонски Брод', 'М', '078/380000', '', '', 'naum.test@t.mk', 'sou-svtest-brod@gmail.com'],
    ];

    /** Columns A..G of "Основни училишта" (row 1 title, row 2 header; G has no header). */
    public array $primary = [
        ['Скопски регион', 'Аеродром', 'ОOУ „Тест Конески“', 'ул„Тестова“ бр.4', 'test_koneski@yahoo.com', '02-2-469-746', ''],
        ['Југозападен регион', 'Струга', 'ООУ „Гоце Тестов“ с.Јабланица', 'с.Јабланица', 'gt.jablanica@yahoo.com,', 'd. 070 123 456   02 3111 222', 'алб'],
        ['Југозападен регион', 'Струга', 'ООУ „Гоце Тестов“ с.Подгорци', 'с.Подгорци', 'gt.podgorci@yahoo.com', '75235637', 'мк/алб'],
        ['Пелагонсиски регион', 'Битола', 'OОУ „Тест Груев“ ', 'ул. Тест бб', 'kontаkt@test-bitola.mk', '047/111111', 'тр'],
        ['Источен регион', 'Македонска Kaменица', 'ООУ „Тест Каменички“', 'ул. Тест 3', 'kamenica.test@yahoo.com', '033/444444', ''],
        ['Источен регион', 'Чешиново Облешево', 'ООУ „Тест Облешевски“ с.Облешево', 'с.Облешево', 'obleshevo.test@yahoo.com', '033/555555', ''],
    ];

    /** Header of "Oбучувачи SREDNI" (B..X); 'Гевгел' without the dot on purpose. */
    public array $matrixHeader = [
        'Име и презиме', 'ОД', 'ЈАЗИК', 'Скопје', 'Куман.', 'Велес', 'Тетово', 'Гостивар', 'Кичево', 'Дебар', 'Охрид',
        'Струга', 'Битола', 'Прилеп', 'Штип', 'Струм.', 'Кочани', 'Св.Никол', 'Кавад.', 'Неготино', 'Делчево', 'Валанд.', 'Гевгел',
    ];

    /** Matrix rows: name, home, language, list of centre headers the trainer is eligible for. */
    public array $matrix = [
        ['Тест Обучувачка Прва', 'Скопје', 'МК', ['Скопје', 'Куман.']],
        ['Ана Тестовска', 'Битола', 'МК', ['Битола', 'Прилеп']],
        ['Тест Османи', 'Скопје', 'АЛ', ['Скопје', 'Тетово']],
        ['Тест Гевгелиски', 'Струмица', 'МК', ['Струм.', 'Гевгел']],
        ['Тест Николски', 'Св.Николе', 'МК', ['Св.Никол']],
        ['Тест Кавадарски', 'Кавад.', 'МК', ['Кавад.', 'Неготино']],
    ];

    /** Topic lists: title cells (column A) and rows [name, education, city]. */
    public array $topics = [
        [
            'sheet' => 'Obuki Napredni tehniki',
            'title' => [2 => 'Листа на обучувачи за тема', 3 => '„ Тема за тестирање на увозот “'],
            'rows' => [
                ['Тест Обучувачка Прва ', 'Професор', 'Скопје'],
                ['Ана Тестоска', 'Магистер', 'Битола'],
                ['Test Osmani', 'Психолог', 'Скопје'],
                ['Нов Тестер', 'Педагог', 'Кривогаштани'],
            ],
        ],
        [
            'sheet' => 'Obuc Sajber Bezbednost',
            'title' => [1 => 'Листа на обучувачи за тема', 2 => '„Втора тема за безбедно,', 3 => ' одговорно тестирање“'],
            'rows' => [
                ['Тест Програмер', 'Доктор на науки', 'Скопје'],
                ['Latin Tester', 'Информатичар', 'Тетово'],
            ],
        ],
    ];

    /** Optional РЕОНИ sheet: [region, municipality, centre]. */
    public ?array $regions = null;

    /** Trainer alias data passed to the importer instead of the real file. */
    public static function aliases(): array
    {
        return [
            'Ана Тестовска' => ['aliases' => ['Ана Тестоска'], 'full_name_sq' => 'Ana Testovska'],
        ];
    }

    public static function make(): self
    {
        return new self;
    }

    public function save(string $path): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $program = $book->createSheet();
        $program->setTitle('ПРОГРАМ');
        $program->fromArray([['', 'ЧЕКОРИ НА ОРГАНИЗАЦИЈА НА ПРОЕКТ', 'ОПИС НА РАБОТАТА']], null, 'A1');

        $secondary = $book->createSheet();
        $secondary->setTitle('АДРЕСИ И ТЕЛЕФОНИ СРЕДНИ');
        $secondary->fromArray([[
            ' Ред. бр. / Nr. rend.', 'Регион', 'Име на средното училиште / Emri i shkollës së mesme', 'Адреса', 'Град', 'Општина /  Komuna',
            'Јазик / Gjuha', 'Телефон/ факс', 'Директор', 'Контакт Директор', 'Електронска пошта', 'Електронска пошта на schools.mk',
            'М', 'А', 'Т', 'Вк', 'Коментар',
        ]], null, 'A1');
        $this->write($secondary, $this->secondary, 2);

        $matrix = $book->createSheet();
        $matrix->setTitle('Oбучувачи SREDNI'); // Latin "O", as in the client's file
        $matrix->fromArray([$this->matrixHeader], null, 'B1');
        foreach ($this->matrix as $i => [$name, $home, $language, $hubs]) {
            $r = $i + 2;
            $matrix->setCellValue("A{$r}", $i + 1);
            $matrix->fromArray([[$name, $home, $language]], null, "B{$r}");
            foreach ($hubs as $hub) {
                $col = array_search($hub, $this->matrixHeader, true);
                $matrix->setCellValueExplicit(chr(ord('B') + $col) . $r, $hub, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        foreach ($this->topics as $topic) {
            $sheet = $book->createSheet();
            $sheet->setTitle($topic['sheet']);
            foreach ($topic['title'] as $row => $text) {
                $sheet->setCellValue("A{$row}", $text);
            }
            $sheet->fromArray([['Обучувач', 'Образование', 'Град']], null, 'B5');
            foreach ($topic['rows'] as $i => $row) {
                $sheet->setCellValue('A' . ($i + 6), $i + 1);
                $sheet->fromArray([$row], null, 'B' . ($i + 6));
            }
        }

        $primary = $book->createSheet();
        $primary->setTitle('Основни училишта');
        $primary->setCellValue('A1', 'Основни училишта (Вкупно ' . count($this->primary) . ')');
        $primary->fromArray([['Регион', 'Место', 'Училиште', 'Адреса', 'E- mail', 'Телефон']], null, 'A2');
        $this->write($primary, $this->primary, 3);

        $example = $book->createSheet();
        $example->setTitle('ПРИМЕР ДИНАМИКА');
        $example->setCellValue('A1', 'Ред. Бр');

        if ($this->regions !== null) {
            $regions = $book->createSheet();
            $regions->setTitle('РЕОНИ');
            $regions->setCellValue('A1', 'Реони за обуки');
            $regions->fromArray([['Регион', 'Општина', 'Центар']], null, 'A3');
            $this->write($regions, $this->regions, 4);
        }

        (new Xlsx($book))->save($path);

        return $path;
    }

    /** The workbook as bytes, for UploadedFile::fake()->createWithContent(). */
    public function contents(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eduwb') . '.xlsx';
        try {
            $this->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** Writes rows as text so phone numbers and codes keep leading zeros. */
    private function write($sheet, array $rows, int $startRow): void
    {
        foreach ($rows as $i => $row) {
            foreach (array_values($row) as $c => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $cell = chr(ord('A') + $c) . ($startRow + $i);
                is_int($value)
                    ? $sheet->setCellValue($cell, $value)
                    : $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
    }
}
