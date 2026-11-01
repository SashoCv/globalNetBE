<?php

namespace App\Services\Edu;

use App\Models\EduMunicipality;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use App\Models\EduTrainerHub;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the client's master-data workbook: РЕОНИ (regions → municipalities
 * → centres, when the sheet exists), secondary and primary schools, the
 * trainer matrix and the per-topic trainer lists.
 *
 * Two steps:
 *  - parse(): reads the file into plain arrays of cell text (JSON-serialisable,
 *    stored as the preview);
 *  - run(): normalises and upserts everything inside one transaction. A dry
 *    run (preview) rolls the transaction back, so the preview counts are
 *    exactly what applying would do. Rows with errors are never written;
 *    applying with unresolved errors is refused unless skip_errors is set.
 *
 * Rows are addressed by a `ref` ("primary:15", "trainers:5", "topic1:12") in
 * errors, warnings, needs_choice and in the resolutions the admin sends back:
 *   ['primary:15' => ['municipality_id' => 3], 'topic2:11' => ['languages' => ['sq']], …]
 */
class EduWorkbookImporter
{
    public const SHEETS = ['regions', 'secondary', 'primary', 'trainers', 'topics'];

    private EduMunicipalityResolver $resolver;

    private array $resolutions = [];

    private array $errors = [];

    private array $warnings = [];

    private array $needs = [];

    private array $summary = [];

    private array $sheetNames = [];

    private ?array $aliasOverride = null;

    // ── parse ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $only  sheet keys to read (self::SHEETS); empty = all
     */
    public function parse(string $path, array $only = []): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        $only = $only ?: self::SHEETS;

        $parsed = [
            'sheets' => [],
            'ignored_sheets' => [],
            'regions' => null,
            'secondary' => null,
            'primary' => null,
            'trainers' => null,
            'topics' => [],
        ];

        foreach ($workbook->getSheetNames() as $name) {
            $sheet = $workbook->getSheetByName($name);
            $rows = $sheet->toArray(null, true, true, true);
            $kind = $this->detect($name, $rows);

            if ($kind === null || !in_array($kind, $only, true) || ($kind !== 'topics' && $parsed[$kind] !== null)) {
                $parsed['ignored_sheets'][] = $name;
                continue;
            }

            if ($kind === 'topics') {
                $key = 'topic' . (count($parsed['topics']) + 1);
                $parsed['topics'][] = ['key' => $key, 'sheet' => $name] + $this->parseTopic($rows);
                $parsed['sheets'][$key] = $name;
                continue;
            }

            $parsed['sheets'][$kind] = $name;
            $parsed[$kind] = match ($kind) {
                'regions' => $this->parseRegions($rows),
                'secondary' => $this->parseSecondary($rows),
                'primary' => $this->parsePrimary($rows),
                'trainers' => $this->parseMatrix($rows, $sheet),
            };
        }

        return $parsed;
    }

    private function detect(string $name, array $rows): ?string
    {
        $k = EduText::words(EduText::fixHomoglyphs($name));
        $head = '';
        foreach (array_slice($rows, 0, 6, true) as $row) {
            $head .= ' ' . EduText::words(EduText::fixHomoglyphs((string) ($row['A'] ?? '')));
        }

        if (str_contains($head, 'листа на обучувачи')) {
            return 'topics';
        }
        if (preg_match('~(^|\s)(реони|реон|региони)(\s|$)~u', $k)) {
            return 'regions';
        }
        if (str_contains($k, 'средни') && (str_contains($k, 'адреси') || str_contains($k, 'телефони') || str_contains($k, 'училишта'))) {
            return 'secondary';
        }
        if (str_contains($k, 'основни')) {
            return 'primary';
        }
        if (str_contains($k, 'обучувачи')) {
            return 'trainers';
        }

        return null;
    }

    private static function cell(array $row, string $col): string
    {
        $v = $row[$col] ?? null;
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v;
        }

        return trim((string) $v);
    }

    /** First row (within the first $limit) whose column $col matches. */
    private function headerRow(array $rows, string $col, callable $test, int $limit = 15): ?int
    {
        foreach (array_slice($rows, 0, $limit, true) as $r => $row) {
            if ($test(EduText::words(EduText::fixHomoglyphs(self::cell($row, $col))))) {
                return (int) $r;
            }
        }

        return null;
    }

    private function parseRegions(array $rows): array
    {
        // Header: the first row naming both a region and a municipality column.
        $header = null;
        $cols = [];
        foreach (array_slice($rows, 0, 15, true) as $r => $row) {
            $found = [];
            foreach ($row as $col => $value) {
                $w = EduText::words(EduText::fixHomoglyphs((string) $value));
                if ($w === '') {
                    continue;
                }
                if (!isset($found['region']) && (str_contains($w, 'регион') || str_contains($w, 'реон'))) {
                    $found['region'] = $col;
                } elseif (!isset($found['municipality']) && (str_contains($w, 'општина') || str_contains($w, 'место'))) {
                    $found['municipality'] = $col;
                } elseif (!isset($found['hub']) && str_contains($w, 'центар')) {
                    $found['hub'] = $col;
                }
            }
            if (isset($found['region'], $found['municipality'])) {
                $header = (int) $r;
                $cols = $found;
                break;
            }
        }

        $out = ['header_row' => $header, 'columns' => $cols, 'rows' => []];
        if ($header === null) {
            return $out;
        }
        $lastRegion = '';
        foreach ($rows as $r => $row) {
            if ($r <= $header) {
                continue;
            }
            $municipality = self::cell($row, $cols['municipality']);
            $region = self::cell($row, $cols['region']);
            // Merged region cells: only the first row of a block has the name.
            $region = $region !== '' ? $region : $lastRegion;
            $lastRegion = $region;
            if ($municipality === '') {
                continue;
            }
            $out['rows'][] = [
                'row' => (int) $r,
                'region' => $region,
                'municipality' => $municipality,
                'hub' => isset($cols['hub']) ? self::cell($row, $cols['hub']) : '',
            ];
        }

        return $out;
    }

    private function parseSecondary(array $rows): array
    {
        $header = $this->headerRow($rows, 'C', fn ($w) => str_contains($w, 'име') || str_contains($w, 'училиште')) ?? 1;
        $out = ['header_row' => $header, 'rows' => []];
        foreach ($rows as $r => $row) {
            if ($r <= $header || self::cell($row, 'C') === '') {
                continue;
            }
            $out['rows'][] = [
                'row' => (int) $r,
                'source_no' => self::cell($row, 'A'),
                'region' => self::cell($row, 'B'),
                'name' => self::cell($row, 'C'),
                'address' => self::cell($row, 'D'),
                'city' => self::cell($row, 'E'),
                'municipality' => self::cell($row, 'F'),
                'language' => self::cell($row, 'G'),
                'phone' => self::cell($row, 'H'),
                'director_name' => self::cell($row, 'I'),
                'director_contact' => self::cell($row, 'J'),
                'email' => self::cell($row, 'K'),
                'schools_mk_email' => self::cell($row, 'L'),
            ];
        }

        return $out;
    }

    private function parsePrimary(array $rows): array
    {
        $header = $this->headerRow($rows, 'C', fn ($w) => $w === 'училиште' || str_starts_with($w, 'име')) ?? 2;
        $out = ['header_row' => $header, 'rows' => []];
        foreach ($rows as $r => $row) {
            if ($r <= $header || self::cell($row, 'C') === '') {
                continue;
            }
            $out['rows'][] = [
                'row' => (int) $r,
                'region' => self::cell($row, 'A'),
                'municipality' => self::cell($row, 'B'),
                'name' => self::cell($row, 'C'),
                'address' => self::cell($row, 'D'),
                'email' => self::cell($row, 'E'),
                'phone' => self::cell($row, 'F'),
                'language' => self::cell($row, 'G'),
            ];
        }

        return $out;
    }

    private function parseMatrix(array $rows, Worksheet $sheet): array
    {
        $header = $this->headerRow($rows, 'B', fn ($w) => str_contains($w, 'име')) ?? 1;
        // Centre columns: everything right of the language column (D) with a header.
        $hubs = [];
        foreach ($rows[$header] ?? [] as $col => $value) {
            if (Coordinate::columnIndexFromString($col) > 4 && self::cell($rows[$header], $col) !== '') {
                $hubs[$col] = self::cell($rows[$header], $col);
            }
        }

        $out = ['header_row' => $header, 'hubs' => $hubs, 'rows' => []];
        foreach ($rows as $r => $row) {
            if ($r <= $header || self::cell($row, 'B') === '') {
                continue;
            }
            $cells = [];
            foreach (array_keys($hubs) as $col) {
                if (self::cell($row, $col) !== '') {
                    $cells[] = $col;
                }
            }
            $out['rows'][] = [
                'row' => (int) $r,
                'name' => self::cell($row, 'B'),
                'home' => self::cell($row, 'C'),
                'language' => self::cell($row, 'D'),
                'hub_columns' => $cells,
            ];
        }

        return $out;
    }

    private function parseTopic(array $rows): array
    {
        $listRow = $this->headerRow($rows, 'A', fn ($w) => str_contains($w, 'листа на обучувачи'), 6) ?? 1;
        $header = $this->headerRow($rows, 'B', fn ($w) => str_starts_with($w, 'обучувач'), 20);

        // The title is the text between the "Листа…" row and the table header.
        $title = [];
        foreach ($rows as $r => $row) {
            if ($r > $listRow && ($header === null || $r < $header)) {
                $text = self::cell($row, 'A');
                if ($text !== '') {
                    $title[] = $text;
                }
            }
        }
        $title = EduText::squish(implode(' ', $title));
        $title = EduText::squish(trim($title, " \t„“\"”'"));

        $out = ['title' => $title, 'header_row' => $header, 'rows' => []];
        if ($header === null) {
            return $out;
        }
        foreach ($rows as $r => $row) {
            if ($r <= $header || self::cell($row, 'B') === '') {
                continue;
            }
            $out['rows'][] = [
                'row' => (int) $r,
                'name' => self::cell($row, 'B'),
                'education' => self::cell($row, 'C'),
                'home' => self::cell($row, 'D'),
            ];
        }

        return $out;
    }

    // ── run ──────────────────────────────────────────────────────────────

    /**
     * @param  array{matrix_topic_id?: int|null, resolutions?: array<string, array>, skip_errors?: bool, trainer_aliases?: array}  $options
     * @return array{applied: bool, blocking_errors: int, sheets: array, summary: array, totals: array, errors: list<array>, warnings: list<array>, needs_choice: list<array>}
     */
    public function run(array $parsed, array $options = [], bool $commit = false): array
    {
        $this->resolutions = $options['resolutions'] ?? [];
        $this->errors = $this->warnings = $this->needs = [];
        $this->summary = [];
        $this->sheetNames = $parsed['sheets'] ?? [];
        $this->aliasOverride = $options['trainer_aliases'] ?? null;

        DB::beginTransaction();
        try {
            $this->resolver = new EduMunicipalityResolver;

            if (($parsed['secondary'] || $parsed['primary'] || $parsed['trainers'] || $parsed['topics'])
                && !$parsed['regions'] && $this->resolver->all()->isEmpty()) {
                $this->error('general', null, null, 'no_geography', 'Нема внесени општини. Прво пуштете EduGeoSeeder или увезете лист РЕОНИ.');
            }

            if ($parsed['regions']) {
                $this->importRegions($parsed['regions']);
                $this->resolver->refresh();
            }
            if ($parsed['secondary']) {
                $this->importSchools(EduSchool::LEVEL_SECONDARY, 'secondary', $parsed['secondary']['rows']);
            }
            if ($parsed['primary']) {
                $this->importSchools(EduSchool::LEVEL_PRIMARY, 'primary', $parsed['primary']['rows']);
            }
            if ($parsed['trainers'] || $parsed['topics']) {
                $this->importTrainers($parsed['trainers'], $parsed['topics'], $options['matrix_topic_id'] ?? null);
            }

            $blocking = count($this->errors);
            $applied = $commit && ($blocking === 0 || !empty($options['skip_errors']));
            $applied ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($this->summary as $entity) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $entity[$k] ?? 0;
            }
        }

        return [
            'applied' => $applied,
            'blocking_errors' => $blocking,
            'sheets' => $this->sheetNames,
            'summary' => $this->summary,
            'totals' => $totals,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'needs_choice' => $this->needs,
        ];
    }

    // ── regions (РЕОНИ) ──────────────────────────────────────────────────

    private function importRegions(array $sheet): void
    {
        $this->summary['regions'] = $this->counter();
        $this->summary['municipalities'] = $this->counter();
        if ($sheet['header_row'] === null) {
            $this->error('regions', null, null, 'no_header', 'Не е пронајдено заглавие со колони „Регион“ и „Општина“.');

            return;
        }

        $regionIds = EduRegion::pluck('id', 'name')->all();
        $seenRegions = [];
        $hubLinks = [];
        $state = []; // municipality id => created | updated | unchanged

        foreach ($sheet['rows'] as $row) {
            $ref = "regions:{$row['row']}";
            if ($this->skipped($ref, 'municipalities')) {
                continue;
            }
            $this->summary['municipalities']['rows']++;
            $regionName = EduText::region($row['region']);
            if ($regionName === null) {
                $this->error('regions', $row['row'], $sheet['columns']['region'] ?? null, 'missing_region', 'Недостасува регион.', $ref);
                continue;
            }
            if (!isset($seenRegions[$regionName])) {
                $seenRegions[$regionName] = true;
                $this->summary['regions']['rows']++;
                if (isset($regionIds[$regionName])) {
                    $this->summary['regions']['unchanged']++;
                } else {
                    $regionIds[$regionName] = EduRegion::create([
                        'name' => $regionName,
                        'sort_order' => (int) EduRegion::max('sort_order') + 1,
                    ])->id;
                    $this->summary['regions']['created']++;
                }
            }

            $municipality = $this->resolveMunicipality($ref, $row['municipality']);
            if (!$municipality) {
                $name = EduText::clean($row['municipality']);
                [$mk, $sq] = EduText::splitBilingual($name, false);
                $municipality = EduMunicipality::create([
                    'name' => $mk,
                    'name_sq' => $sq,
                    'region_id' => $regionIds[$regionName],
                    'aliases' => $mk !== $name ? [$name] : null,
                ]);
                $state[$municipality->id] = 'created';
                $this->warn('regions', $row['row'], $sheet['columns']['municipality'] ?? null, 'municipality_created', "Нова општина „{$mk}“ во регион {$regionName}.", $ref);
                $this->resolver->refresh();
            } elseif ($municipality->region_id !== $regionIds[$regionName]) {
                $municipality->update(['region_id' => $regionIds[$regionName]]);
                $state[$municipality->id] = 'updated';
            } else {
                $state[$municipality->id] ??= 'unchanged';
            }

            if ($row['hub'] !== '') {
                $hubLinks[] = [$ref, $row['row'], $municipality->id, $row['hub']];
            }
        }

        // Centres last: they can be municipalities listed further down.
        $this->resolver->refresh();
        foreach ($hubLinks as [$ref, $rowNo, $municipalityId, $hubText]) {
            $hub = $this->resolver->resolve($hubText)['municipality'];
            if (!$hub) {
                $this->error('regions', $rowNo, $sheet['columns']['hub'] ?? null, 'unknown_hub', "Непознат центар: „{$hubText}“.", $ref, $hubText);
                continue;
            }
            if (!$hub->is_training_hub || $hub->hub_municipality_id !== $hub->id) {
                $hub->update(['is_training_hub' => true, 'hub_municipality_id' => $hub->id]);
            }
            $municipality = EduMunicipality::find($municipalityId);
            if ($municipality->hub_municipality_id !== $hub->id) {
                $municipality->update(['hub_municipality_id' => $hub->id]);
                if (($state[$municipalityId] ?? null) === 'unchanged') {
                    $state[$municipalityId] = 'updated';
                }
            }
        }
        foreach ($state as $outcome) {
            $this->summary['municipalities'][$outcome]++;
        }
        $this->resolver->refresh();
    }

    // ── schools ──────────────────────────────────────────────────────────

    private function importSchools(string $level, string $sheetKey, array $rows): void
    {
        $entity = "schools_{$level}";
        $this->summary[$entity] = $this->counter() + ['default_language' => 0];
        $schoolsMk = [];
        $matcher = new EduSchoolMatcher;
        $hasSchools = EduSchool::where('level', $level)->exists();
        $municipalityColumn = $level === EduSchool::LEVEL_SECONDARY ? 'F' : 'B';

        // Pass 1: municipality and name of every row.
        $prepared = [];
        foreach ($rows as $row) {
            $ref = "{$sheetKey}:{$row['row']}";
            $r = $row['row'];
            $this->summary[$entity]['rows']++;
            if ($this->skipped($ref, $entity)) {
                continue;
            }
            $res = $this->resolutions[$ref] ?? [];

            // Municipality: the "Општина" column; for secondary schools the
            // "Град" column is the fallback.
            $municipality = null;
            $remainder = null;
            if (!empty($res['municipality_id'])) {
                $municipality = $this->resolver->find((int) $res['municipality_id']);
            } else {
                $found = $this->resolver->resolve($row['municipality']);
                if (!$found['municipality'] && $level === EduSchool::LEVEL_SECONDARY) {
                    $found = $this->resolver->resolve($row['city']);
                }
                $municipality = $found['municipality'];
                $remainder = $found['remainder'];
            }
            if (!$municipality) {
                $value = $row['municipality'] !== '' ? $row['municipality'] : ($row['city'] ?? '');
                $this->error($sheetKey, $r, $municipalityColumn, 'unknown_municipality', "Непозната општина: „{$value}“.", $ref, $value);
                continue;
            }

            $names = EduSchool::nameFields($row['name'], $municipality->name);
            if ($names['name_key'] === '') {
                $this->error($sheetKey, $r, 'C', 'missing_name', 'Името на училиштето е празно.', $ref);
                continue;
            }
            $prepared[] = compact('row', 'ref', 'res', 'municipality', 'remainder', 'names');
        }

        // Schools that differ only by type prefix (СОУ / СОМУ / ОСТУ
        // „Гостивар“) keep the prefix in their key.
        $groups = [];
        foreach ($prepared as $i => $p) {
            $groups[$p['municipality']->id . '|' . $p['names']['name_key']][] = $i;
        }
        foreach ($groups as $members) {
            $prefixes = array_map(fn ($i) => $prepared[$i]['names']['type_prefix'], $members);
            $distinct = count($members) > 1
                && collect($prefixes)->contains(fn ($p) => !EduText::samePrefixFamily($p, $prefixes[0]));
            if ($distinct) {
                foreach ($members as $i) {
                    $prepared[$i]['names']['name_key'] = EduText::keyWithPrefix($prepared[$i]['names']['name'], $prepared[$i]['municipality']->name);
                }
            }
        }

        // Pass 2: write.
        $seen = [];
        foreach ($prepared as ['row' => $row, 'ref' => $ref, 'res' => $res, 'municipality' => $municipality, 'remainder' => $remainder, 'names' => $names]) {
            $r = $row['row'];
            $dupKey = $municipality->id . '|' . $names['name_key'];
            if (isset($seen[$dupKey])) {
                $this->error($sheetKey, $r, 'C', 'duplicate_row', "Истото училиште е веќе во ред {$seen[$dupKey]}.", $ref, $names['display_name']);
                continue;
            }
            $seen[$dupKey] = $r;

            $attrs = $this->schoolAttributes($level, $sheetKey, $row, $names, $municipality, $remainder, $ref, $schoolsMk);

            // Existing school: the admin's choice, else the natural key.
            $school = null;
            if (!empty($res['school_id'])) {
                $school = EduSchool::where('level', $level)->find((int) $res['school_id']);
            }
            $school ??= EduSchool::where('level', $level)
                ->where('municipality_id', $municipality->id)
                ->where('name_key', $names['name_key'])
                ->first();

            if (!$school && $hasSchools && empty($res['create'])) {
                $match = $matcher->match($names['display_name'], $level, $municipality->id, $municipality->name);
                $close = array_values(array_filter($match['candidates'], fn ($c) => $c['score'] >= (float) config('edu.school_match_threshold', 0.85)));
                if ($close) {
                    $this->need($sheetKey, $r, 'school_match', "Можно е ова училиште веќе да постои: „{$close[0]['display_name']}“.", $ref, $names['display_name'], $close, null, 'create');
                }
            }

            // An empty language cell means the configured default (Macedonian),
            // but only for a new school or one whose language is still unknown:
            // a language set in the app is never replaced by the default.
            if ($attrs['languages'] === [] && EduText::squish($row['language']) === '' && !array_key_exists('languages', $res)
                && ($default = self::schoolDefaultLanguages()) && (!$school || ($school->languages ?? []) === [])) {
                $attrs['languages'] = $default;
                $this->summary[$entity]['default_language']++;
            }

            if (!$school) {
                EduSchool::create($attrs);
                $this->summary[$entity]['created']++;
                continue;
            }

            // On update an empty cell never erases what is already there.
            $school->fill(array_filter($attrs, fn ($v) => $v !== null && $v !== [] && $v !== ''));
            if ($school->isDirty()) {
                $school->save();
                $this->summary[$entity]['updated']++;
            } else {
                $this->summary[$entity]['unchanged']++;
            }
        }
    }

    private function schoolAttributes(string $level, string $sheetKey, array $row, array $names, EduMunicipality $municipality, ?string $remainder, string $ref, array &$schoolsMk): array
    {
        $r = $row['row'];
        $res = $this->resolutions[$ref] ?? [];
        $secondary = $level === EduSchool::LEVEL_SECONDARY;

        // Region column vs the municipality's region: the municipality wins.
        $sheetRegion = EduText::region($row['region']);
        $municipalityRegion = $municipality->region?->name;
        if ($sheetRegion && $municipalityRegion && $sheetRegion !== $municipalityRegion) {
            $this->warn($sheetKey, $r, $secondary ? 'B' : 'A', 'region_mismatch', "Регионот „{$sheetRegion}“ не одговара на општината {$municipality->name} ({$municipalityRegion}); се задржува регионот на општината.", $ref);
        }
        if ($remainder) {
            $this->warn($sheetKey, $r, $secondary ? 'F' : 'B', 'municipality_extra_text', "Вишок текст по општината е преместен во коментар: „{$remainder}“.", $ref, $remainder);
        }

        // Languages: an empty cell is filled with the configured default by
        // the caller (edu.import.school_default_language); a text that is not
        // a known language stays unknown.
        $languages = array_key_exists('languages', $res) ? \App\Support\Edu\EduLanguage::normalize((array) $res['languages']) : EduText::language($row['language']);
        if ($row['language'] !== '' && $languages === [] && !array_key_exists('languages', $res)) {
            $this->warn($sheetKey, $r, 'G', 'unknown_language', "Непознат јазик: „{$row['language']}“.", $ref, $row['language']);
        }

        [$address, $addressSq] = EduText::splitBilingual(EduText::clean($row['address']), false);

        $phones = EduText::phones($row['phone']);
        if (preg_match('~\d~', $row['phone']) && !$phones['phones'] && !$phones['director']) {
            $this->warn($sheetKey, $r, $secondary ? 'H' : 'F', 'no_phone', "Не е препознаен телефонски број: „{$row['phone']}“.", $ref, $row['phone']);
        }

        $emailCol = $secondary ? 'K' : 'E';
        $emails = EduText::emails($row['email']);
        foreach ($emails['invalid'] as $bad) {
            $this->warn($sheetKey, $r, $emailCol, 'invalid_email', "Неважечка е-пошта: „{$bad}“.", $ref, $bad);
        }
        foreach ($emails['warnings'] as $w) {
            $this->warn($sheetKey, $r, $emailCol, 'suspicious_email', $w, $ref);
        }

        $schoolsMkEmail = null;
        if ($secondary && $row['schools_mk_email'] !== '') {
            $parsedMk = EduText::emails($row['schools_mk_email']);
            $schoolsMkEmail = $parsedMk['emails'][0] ?? null;
            if (!$schoolsMkEmail) {
                $this->warn($sheetKey, $r, 'L', 'invalid_email', "Неважечка schools.mk адреса: „{$row['schools_mk_email']}“.", $ref);
            } elseif (!str_ends_with($schoolsMkEmail, '@schools.mk')) {
                $this->warn($sheetKey, $r, 'L', 'schools_mk_domain', "Адресата не е на schools.mk: {$schoolsMkEmail}.", $ref, $schoolsMkEmail);
            } elseif (!$this->schoolsMkFitsPlace($schoolsMkEmail, $municipality, $row)) {
                $this->warn($sheetKey, $r, 'L', 'schools_mk_place', "Адресата {$schoolsMkEmail} изгледа дека е на училиште од друго место.", $ref, $schoolsMkEmail);
            }
            if ($schoolsMkEmail && isset($schoolsMk[$schoolsMkEmail])) {
                $this->warn($sheetKey, $r, 'L', 'schools_mk_duplicate', "Истата schools.mk адреса е и во ред {$schoolsMk[$schoolsMkEmail]}: {$schoolsMkEmail}.", $ref, $schoolsMkEmail);
            } elseif ($schoolsMkEmail) {
                $schoolsMk[$schoolsMkEmail] = $r;
            }
        }

        $directorContact = $secondary ? EduText::squish($row['director_contact']) : implode(', ', $phones['director']);
        $sourceNo = $secondary && ctype_digit($row['source_no'] ?? '') ? (int) $row['source_no'] : null;

        return [
            'level' => $level,
            'source_no' => $sourceNo,
            'display_name' => $names['display_name'],
            'name' => $names['name'],
            'name_sq' => $names['name_sq'],
            'type_prefix' => $names['type_prefix'],
            'settlement' => $names['settlement'],
            'name_key' => $names['name_key'],
            'municipality_id' => $municipality->id,
            'address' => $address !== '' ? $address : null,
            'address_sq' => $addressSq,
            'languages' => $languages,
            'phones' => $phones['phones'],
            'phone_raw' => EduText::squish($row['phone']) ?: null,
            'director_name' => $secondary ? (EduText::squish($row['director_name']) ?: null) : null,
            'director_contact' => $directorContact !== '' ? $directorContact : null,
            'emails' => $emails['emails'],
            'email_raw' => EduText::squish($row['email']) ?: null,
            'schools_mk_email' => $schoolsMkEmail,
            'comment' => $remainder,
        ];
    }

    /**
     * schools.mk addresses end in the place ("sou-…-kumanovo@schools.mk"); a
     * different place usually means the address was copied from another row.
     */
    private function schoolsMkFitsPlace(string $email, EduMunicipality $municipality, array $row): bool
    {
        $parts = explode('-', strstr($email, '@', true) ?: '');
        if (count($parts) < 3) {
            return true;
        }
        $flat = fn (?string $s) => str_replace(' ', '', EduText::translitKey($s));
        $targets = array_filter([
            $flat($municipality->name),
            $flat($municipality->name_sq),
            $municipality->part_of_skopje ? 'skopje' : null,
            $flat($row['city'] ?? ''),
        ]);
        $address = $flat($row['address'] ?? '');

        // The place is the last part, sometimes followed by one more ("…-tetovo-sredno").
        foreach (array_slice($parts, -2) as $part) {
            $place = $flat($part);
            if ($place === '') {
                continue;
            }
            foreach ($targets as $target) {
                if (str_starts_with($target, $place) || str_starts_with($place, $target)) {
                    return true;
                }
            }
            // A village school uses the village ("…-zhitoshe").
            if (strlen($place) >= 4 && str_contains($address, $place)) {
                return true;
            }
        }

        return false;
    }

    // ── trainers and topics ──────────────────────────────────────────────

    private function importTrainers(?array $matrix, array $topics, ?int $matrixTopicId): void
    {
        $this->summary['trainers'] = $this->counter() + ['mentions' => 0, 'unknown_language' => 0, 'language_from_script' => 0];
        $this->summary['topics'] = $this->counter() + ['links_created' => 0];
        $threshold = (float) config('edu.trainer_match_threshold', 0.85);

        // Matrix columns → centres.
        $hubByColumn = [];
        if ($matrix) {
            foreach ($matrix['hubs'] as $col => $header) {
                $hub = $this->resolver->hubFromHeader($header);
                if ($hub) {
                    $hubByColumn[$col] = $hub->id;
                } else {
                    $this->error('trainers', $matrix['header_row'], $col, 'unknown_hub', "Непознат центар во заглавието: „{$header}“.", "trainers:{$matrix['header_row']}:{$col}", $header);
                }
            }
        }

        // Topics.
        $topicIds = [];
        $existingTopics = EduTopic::all();
        foreach ($topics as $topic) {
            $this->summary['topics']['rows']++;
            if ($topic['title'] === '') {
                $this->error($topic['key'], null, 'A', 'missing_topic_title', 'Не е пронајден насловот на темата.', $topic['key']);
                continue;
            }
            $titleKey = EduText::words($topic['title']);
            $existing = $existingTopics->first(fn ($t) => EduText::words(EduText::squish(trim($t->title, '„“"'))) === $titleKey);
            if ($existing) {
                $topicIds[$topic['key']] = $existing->id;
                $this->summary['topics']['unchanged']++;
            } else {
                $topicIds[$topic['key']] = EduTopic::create(['title' => $topic['title']])->id;
                $this->summary['topics']['created']++;
            }
        }

        // Every mention of a trainer across the sheets.
        $mentions = [];
        foreach ($matrix['rows'] ?? [] as $row) {
            $mentions[] = [
                'ref' => "trainers:{$row['row']}", 'sheet' => 'trainers', 'row' => $row['row'],
                'name' => EduText::clean($row['name']), 'home' => $row['home'],
                'languages' => EduText::language($row['language']), 'language_raw' => $row['language'],
                'hubs' => array_values(array_unique(array_filter(array_map(fn ($c) => $hubByColumn[$c] ?? null, $row['hub_columns'])))),
                'topic' => null, 'education' => '',
            ];
        }
        foreach ($topics as $topic) {
            if (!isset($topicIds[$topic['key']])) {
                continue;
            }
            foreach ($topic['rows'] as $row) {
                $mentions[] = [
                    'ref' => "{$topic['key']}:{$row['row']}", 'sheet' => $topic['key'], 'row' => $row['row'],
                    'name' => EduText::clean($row['name']), 'home' => $row['home'],
                    'languages' => [], 'language_raw' => '', 'hubs' => null,
                    'topic' => $topicIds[$topic['key']], 'education' => EduText::squish($row['education']),
                ];
            }
        }
        $this->summary['trainers']['mentions'] = count($mentions);

        $mentions = array_values(array_filter($mentions, function ($m) {
            if ($this->skipped($m['ref'], 'trainers')) {
                return false;
            }

            return $m['name'] !== '';
        }));

        // Known spellings → canonical name.
        $aliasData = $this->aliasData();
        $canonicalByKey = [];
        foreach ($aliasData as $canonical => $info) {
            foreach (array_merge([$canonical], $info['aliases'] ?? []) as $form) {
                $canonicalByKey[EduText::personKey($form)] = $canonical;
                $canonicalByKey['t:' . EduText::translitKey($form)] = $canonical;
            }
        }
        foreach ($mentions as $i => $m) {
            $mentions[$i]['canonical'] = $canonicalByKey[EduText::personKey($m['name'])]
                ?? $canonicalByKey['t:' . EduText::translitKey($m['name'])] ?? null;
            $mentions[$i]['key'] = EduText::personKey($mentions[$i]['canonical'] ?? $m['name']);
            $mentions[$i]['translit'] = EduText::translitKey($mentions[$i]['canonical'] ?? $m['name']);
        }

        // Cluster mentions of the same person (union-find on refs).
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function ($a, $b) use (&$parent, $find) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };
        $byKey = [];
        foreach ($mentions as $m) {
            $parent[$m['ref']] = $m['ref'];
        }
        foreach ($mentions as $m) {
            foreach (['k:' . $m['key'], 't:' . $m['translit']] as $k) {
                if (isset($byKey[$k])) {
                    $union($byKey[$k], $m['ref']);
                } else {
                    $byKey[$k] = $m['ref'];
                }
            }
        }
        foreach ($mentions as $m) {
            $mergeRef = $this->resolutions[$m['ref']]['merge_ref'] ?? null;
            if ($mergeRef && isset($parent[$mergeRef])) {
                $union($mergeRef, $m['ref']);
            }
        }
        $clusters = [];
        foreach ($mentions as $m) {
            $clusters[$find($m['ref'])][] = $m;
        }

        // Existing trainers and their keys.
        $existing = EduTrainer::all()->map(fn (EduTrainer $t) => [
            'trainer' => $t,
            'keys' => array_unique(array_map([EduText::class, 'personKey'], array_filter(array_merge([$t->full_name, $t->full_name_sq], $t->aliases ?? [])))),
            'translits' => array_unique(array_map([EduText::class, 'translitKey'], array_filter(array_merge([$t->full_name, $t->full_name_sq], $t->aliases ?? [])))),
        ]);

        $done = [];
        foreach ($clusters as $root => $group) {
            $this->summary['trainers']['rows']++;
            $first = $group[0];
            $refs = array_column($group, 'ref');
            $resolution = [];
            foreach ($refs as $ref) {
                $resolution += $this->resolutions[$ref] ?? [];
            }

            // Which existing trainer is this?
            $trainer = null;
            if (!empty($resolution['trainer_id'])) {
                $trainer = EduTrainer::find((int) $resolution['trainer_id']);
            }
            if (!$trainer) {
                $keys = array_unique(array_merge(array_column($group, 'key'), array_map(fn ($m) => EduText::personKey($m['name']), $group)));
                $translits = array_unique(array_merge(array_column($group, 'translit'), array_map(fn ($m) => EduText::translitKey($m['name']), $group)));
                $hit = $existing->first(fn ($e) => array_intersect($keys, $e['keys']) || array_intersect($translits, $e['translits']));
                $trainer = $hit['trainer'] ?? null;
            }

            // Not known: maybe a different spelling of someone already known.
            if (!$trainer && empty($resolution['create'])) {
                $candidates = [];
                foreach ($existing as $e) {
                    $score = max(array_map(fn ($t) => EduText::similarity($first['translit'], $t), $e['translits'] ?: ['']));
                    if ($score >= $threshold) {
                        $candidates[] = ['trainer_id' => $e['trainer']->id, 'label' => $e['trainer']->full_name, 'score' => round($score, 3)];
                    }
                }
                foreach ($done as $otherRoot => $other) {
                    $score = EduText::similarity($first['translit'], $other['translit']);
                    if ($score >= $threshold) {
                        $candidates[] = ['ref' => $otherRoot, 'label' => $other['name'], 'score' => round($score, 3)];
                    }
                }
                if ($candidates) {
                    usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $this->need($first['sheet'], $first['row'], 'trainer_duplicate', "Можен дупликат: „{$first['name']}“ и „{$candidates[0]['label']}“. Спои или креирај нов.", $first['ref'], $first['name'], array_slice($candidates, 0, 3), null, 'create');
                }
            }

            $attrs = $this->trainerAttributes($group, $resolution, $aliasData);
            $done[$root] = ['translit' => $first['translit'], 'name' => $attrs['full_name']];

            // No language in the sheets and a Latin-script name: Albanian,
            // applied without asking (client decision). Only for a new trainer
            // or one whose language is still unknown, so a language set in
            // the app or chosen in the preview (resolution) always wins.
            if ($attrs['languages'] === [] && !array_key_exists('languages', $resolution) && empty($resolution['language'])
                && EduText::script($attrs['full_name']) === 'latin'
                && (!$trainer || ($trainer->languages ?? []) === [])) {
                $attrs['languages'] = ['sq'];
                $this->summary['trainers']['language_from_script']++;
                $this->warn($first['sheet'], $first['row'], null, 'trainer_language_sq',
                    "Јазикот на „{$attrs['full_name']}“ не е наведен; името е на латиница, поставен е албански.",
                    $first['ref'], $attrs['full_name']);
            }

            if (!$trainer) {
                $trainer = EduTrainer::create($attrs);
                $this->summary['trainers']['created']++;
                $changed = true;
            } else {
                $update = array_filter($attrs, fn ($v) => $v !== null && $v !== [] && $v !== '');
                unset($update['full_name'], $update['name_key']);
                $update['aliases'] = $this->mergeAliases($trainer, $attrs);
                if (!$update['aliases']) {
                    unset($update['aliases']);
                }
                $trainer->fill($update);
                $changed = $trainer->isDirty();
                if ($changed) {
                    $trainer->save();
                }
            }

            // Matrix: replace this trainer's centres for the matrix topic.
            $hubs = null;
            foreach ($group as $m) {
                if ($m['hubs'] !== null) {
                    $hubs = array_values(array_unique(array_merge($hubs ?? [], $m['hubs'])));
                }
            }
            if ($hubs !== null) {
                $current = EduTrainerHub::where('trainer_id', $trainer->id)
                    ->when($matrixTopicId, fn ($q) => $q->where('topic_id', $matrixTopicId), fn ($q) => $q->whereNull('topic_id'))
                    ->pluck('hub_municipality_id')->all();
                sort($current);
                sort($hubs);
                if ($current !== $hubs) {
                    EduTrainerHub::where('trainer_id', $trainer->id)
                        ->when($matrixTopicId, fn ($q) => $q->where('topic_id', $matrixTopicId), fn ($q) => $q->whereNull('topic_id'))
                        ->delete();
                    foreach ($hubs as $hubId) {
                        EduTrainerHub::create(['trainer_id' => $trainer->id, 'hub_municipality_id' => $hubId, 'topic_id' => $matrixTopicId]);
                    }
                    $changed = true;
                }
            }

            if (!$trainer->wasRecentlyCreated) {
                $this->summary['trainers'][$changed ? 'updated' : 'unchanged']++;
            }

            // Topic lists.
            $topicIdsForTrainer = array_values(array_unique(array_filter(array_column($group, 'topic'))));
            if ($topicIdsForTrainer) {
                $attached = DB::table('edu_topic_trainers')->where('trainer_id', $trainer->id)->pluck('topic_id')->all();
                $new = array_diff($topicIdsForTrainer, $attached);
                if ($new) {
                    $trainer->topics()->attach($new);
                    $this->summary['topics']['links_created'] += count($new);
                }
            }

            if ($attrs['languages'] === [] && ($trainer->languages ?? []) === []) {
                $this->summary['trainers']['unknown_language']++;
            }

            // Home municipality not recognised.
            $homeText = collect($group)->sortBy(fn ($m) => $m['sheet'] === 'trainers' ? 0 : 1)->pluck('home')->first(fn ($h) => $h !== '');
            if ($homeText && !$attrs['home_municipality_id']) {
                $this->warn($first['sheet'], $first['row'], $first['sheet'] === 'trainers' ? 'C' : 'D', 'unknown_home', "Непознат град/општина на обучувачот: „{$homeText}“.", $first['ref'], $homeText);
            }
        }
    }

    private function trainerAttributes(array $group, array $resolution, array $aliasData): array
    {
        $canonical = collect($group)->pluck('canonical')->filter()->first();
        $names = array_values(array_unique(array_column($group, 'name')));
        $cyrillic = array_values(array_filter($names, fn ($n) => EduText::script($n) === 'cyrillic'));
        $latin = array_values(array_filter($names, fn ($n) => EduText::script($n) === 'latin'));

        $fullName = $canonical ?? $cyrillic[0] ?? $names[0];
        $fullNameSq = ($canonical ? ($aliasData[$canonical]['full_name_sq'] ?? null) : null) ?? $latin[0] ?? null;
        $aliases = array_merge($names, $canonical ? ($aliasData[$canonical]['aliases'] ?? []) : []);
        $aliases = array_values(array_unique(array_filter($aliases, fn ($a) => $a !== $fullName && $a !== $fullNameSq)));

        $languages = [];
        foreach ($group as $m) {
            $languages = array_merge($languages, $m['languages']);
        }
        if (array_key_exists('languages', $resolution)) {
            $languages = (array) $resolution['languages'];
        } elseif (!empty($resolution['language'])) {
            $languages = [$resolution['language']];
        }

        // Home: the matrix "ОД" column first, else the topic list's city.
        $home = null;
        $homeText = null;
        foreach (collect($group)->sortBy(fn ($m) => $m['sheet'] === 'trainers' ? 0 : 1) as $m) {
            if ($m['home'] === '') {
                continue;
            }
            $homeText ??= EduText::squish($m['home']);
            $home ??= $this->resolver->resolve($m['home'])['municipality'];
        }
        if (!empty($resolution['home_municipality_id'])) {
            $home = $this->resolver->find((int) $resolution['home_municipality_id']) ?? $home;
        }

        return [
            'full_name' => $fullName,
            'full_name_sq' => $fullNameSq,
            'name_key' => EduText::personKey($fullName),
            'aliases' => $aliases ?: null,
            'languages' => \App\Support\Edu\EduLanguage::normalize($languages),
            'home_municipality_id' => $home?->id,
            'home_city_text' => $homeText ? mb_substr($homeText, 0, 100) : null,
            'education' => collect($group)->pluck('education')->first(fn ($e) => $e !== '') ?: null,
        ];
    }

    /**
     * Languages an empty school language cell stands for (config
     * edu.import.school_default_language; null keeps the language unknown).
     *
     * @return list<string>
     */
    private static function schoolDefaultLanguages(): array
    {
        $code = config('edu.import.school_default_language');

        return \App\Support\Edu\EduLanguage::isValid($code) ? [$code] : [];
    }

    private function mergeAliases(EduTrainer $trainer, array $attrs): ?array
    {
        $current = $trainer->aliases ?? [];
        $merged = array_values(array_unique(array_merge($current, $attrs['aliases'] ?? [])));
        $merged = array_values(array_filter($merged, fn ($a) => $a !== $trainer->full_name && $a !== ($attrs['full_name_sq'] ?? $trainer->full_name_sq)));

        return $merged === $current ? null : ($merged ?: null);
    }

    private function aliasData(): array
    {
        if ($this->aliasOverride !== null) {
            return $this->aliasOverride;
        }
        $path = database_path('seeders/data/edu/trainer_aliases.php');

        return is_file($path) ? require $path : [];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function resolveMunicipality(string $ref, string $text): ?EduMunicipality
    {
        $res = $this->resolutions[$ref] ?? [];
        if (!empty($res['municipality_id'])) {
            return $this->resolver->find((int) $res['municipality_id']);
        }

        return $this->resolver->resolve($text)['municipality'];
    }

    private function skipped(string $ref, string $entity, bool $count = true): bool
    {
        if (empty($this->resolutions[$ref]['skip'])) {
            return false;
        }
        if ($count && isset($this->summary[$entity])) {
            $this->summary[$entity]['skipped']++;
        }

        return true;
    }

    private function counter(): array
    {
        return ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0];
    }

    private function entityFor(string $sheetKey): ?string
    {
        return match (true) {
            $sheetKey === 'secondary' => 'schools_secondary',
            $sheetKey === 'primary' => 'schools_primary',
            $sheetKey === 'regions' => 'municipalities',
            $sheetKey === 'trainers', str_starts_with($sheetKey, 'topic') => 'trainers',
            default => null,
        };
    }

    private function item(string $sheetKey, ?int $row, ?string $column, string $code, string $message, ?string $ref, ?string $value): array
    {
        return [
            'ref' => $ref ?? ($row ? "{$sheetKey}:{$row}" : $sheetKey),
            'sheet' => $this->sheetNames[$sheetKey] ?? $sheetKey,
            'sheet_key' => $sheetKey,
            'row' => $row,
            'column' => $column,
            'code' => $code,
            'message' => $message,
            'value' => $value,
        ];
    }

    private function error(string $sheetKey, ?int $row, ?string $column, string $code, string $message, ?string $ref = null, ?string $value = null): void
    {
        $this->errors[] = $this->item($sheetKey, $row, $column, $code, $message, $ref, $value);
        if (($entity = $this->entityFor($sheetKey)) && isset($this->summary[$entity])) {
            $this->summary[$entity]['errors']++;
        }
    }

    private function warn(string $sheetKey, ?int $row, ?string $column, string $code, string $message, ?string $ref = null, ?string $value = null): void
    {
        $this->warnings[] = $this->item($sheetKey, $row, $column, $code, $message, $ref, $value);
    }

    private function need(string $sheetKey, int $row, string $kind, string $message, string $ref, ?string $value, array $candidates, ?array $suggestion, mixed $default): void
    {
        $this->needs[] = [
            'ref' => $ref,
            'sheet' => $this->sheetNames[$sheetKey] ?? $sheetKey,
            'sheet_key' => $sheetKey,
            'row' => $row,
            'kind' => $kind,
            'message' => $message,
            'value' => $value,
            'candidates' => $candidates,
            'suggestion' => $suggestion,
            'default' => $default,
        ];
    }
}
