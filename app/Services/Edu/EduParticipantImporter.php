<?php

namespace App\Services\Edu;

use App\Models\EduImport;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduSchool;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Imports a program's participant list, or its per-school counts
 * (училиште, М, А, Т[, Вк]) when there is no list of people.
 *
 * Same two steps as the workbook import:
 *  - parse(): finds the sheet and header row and reads the rows as text
 *    (JSON-serialisable, stored as the preview);
 *  - run(): matches schools, languages and existing participants and
 *    upserts inside one transaction; a dry run (preview) rolls back, so the
 *    preview is exactly what applying does. Rows with errors are never
 *    written and block applying until fixed or skipped.
 *
 * Rows are addressed by ref ("participants:12", "counts:7"); the admin's
 * choices come back as resolutions: {ref: {school_id?, language?, skip?}}.
 *
 * Participants are matched by (program, e-mail, name) when there is an
 * e-mail, otherwise by (program, school, name); re-importing updates them.
 * Participants missing from the file are only reported.
 */
class EduParticipantImporter
{
    public const KIND_PARTICIPANTS = EduImport::TYPE_PARTICIPANTS;
    public const KIND_COUNTS = EduImport::TYPE_SCHOOL_COUNTS;

    private const FIELDS = ['full_name', 'first_name', 'last_name', 'school', 'municipality', 'language', 'email', 'phone', 'position'];
    private const COUNT_FIELDS = ['count_mk', 'count_sq', 'count_tr', 'count_total'];

    private EduSchoolMatcher $matcher;

    private EduMunicipalityResolver $resolver;

    private array $resolutions = [];

    private array $errors = [];

    private array $warnings = [];

    private array $needs = [];

    private array $summary = [];

    private array $rows = [];

    private string $sheetKey = 'participants';

    private string $sheetName = '';

    /** field => column letter of the parsed sheet */
    private array $columnLetters = [];

    public function __construct(private EduProgramCounts $counts)
    {
    }

    // ── parse ────────────────────────────────────────────────────────────

    /**
     * @return array{kind: string, sheet: string|null, sheets: array<string, string>, sheet_names: list<string>, header_row: int|null, columns: array<string, string>, headers: array<string, string>, extra_columns: array<string, string>, rows: list<array>}
     */
    public function parse(string $path, string $kind, ?string $sheet = null, ?int $headerRow = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $names = $book->getSheetNames();

        $out = ['kind' => $kind, 'sheet' => null, 'sheets' => [], 'sheet_names' => $names, 'header_row' => null, 'columns' => [], 'headers' => [], 'extra_columns' => [], 'rows' => []];
        $candidates = $sheet !== null ? array_values(array_filter($names, fn ($n) => $n === $sheet || EduText::words($n) === EduText::words($sheet))) : $names;

        foreach ($candidates as $name) {
            $rows = $book->getSheetByName($name)->toArray(null, true, true, true);
            $found = $this->detectHeader($rows, $kind, $headerRow);
            if ($found === null) {
                continue;
            }
            [$header, $columns, $extra] = $found;
            $out['sheet'] = $name;
            $out['header_row'] = $header;
            $out['columns'] = $columns;
            $out['headers'] = array_map(fn ($v) => self::text($v), $rows[$header] ?? []);
            $out['extra_columns'] = $extra;
            $out['rows'] = $this->readRows($rows, $header, $columns, $extra, $kind);
            break;
        }
        if ($out['sheet'] === null && $candidates) {
            $out['sheet'] = $candidates[0];
        }
        if ($out['sheet'] !== null) {
            $out['sheets'] = [($kind === self::KIND_COUNTS ? 'counts' : 'participants') => $out['sheet']];
        }

        return $out;
    }

    /** @return array{0: int, 1: array<string, string>, 2: array<string, string>}|null */
    private function detectHeader(array $rows, string $kind, ?int $headerRow): ?array
    {
        $scan = $headerRow !== null ? [$headerRow => $rows[$headerRow] ?? []] : array_slice($rows, 0, 20, true);
        foreach ($scan as $r => $row) {
            $columns = [];
            $extra = [];
            foreach ($row as $col => $value) {
                $text = self::text($value);
                if ($text === '') {
                    continue;
                }
                $field = $this->headerField($text, $kind);
                if ($field !== null && !isset($columns[$field])) {
                    $columns[$field] = $col;
                } elseif ($field !== 'number') {
                    $extra[$col] = $text;
                }
            }
            unset($columns['number']);

            $ok = $kind === self::KIND_COUNTS
                ? isset($columns['school']) && array_intersect_key($columns, array_flip(self::COUNT_FIELDS))
                : isset($columns['school']) && (isset($columns['full_name']) || isset($columns['first_name'], $columns['last_name'])) && count($columns) >= 3;
            if ($ok) {
                if ($kind === self::KIND_COUNTS) {
                    $extra = [];
                }

                return [(int) $r, $columns, $extra];
            }
        }

        return null;
    }

    private function headerField(string $text, string $kind): ?string
    {
        $h = EduText::words(EduText::fixHomoglyphs($text));
        if ($h === '') {
            return null;
        }
        if ($kind === self::KIND_COUNTS) {
            foreach (config('edu.count_headers', []) as $field => $synonyms) {
                if (in_array($h, $synonyms, true)) {
                    return $field;
                }
            }
        }
        $words = explode(' ', $h);
        foreach (config('edu.import_headers', []) as $field => $synonyms) {
            foreach ($synonyms as $s) {
                if ($h === $s || (mb_strlen($s) <= 3 ? in_array($s, $words, true) : str_contains($h, $s))) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function readRows(array $rows, int $header, array $columns, array $extra, string $kind): array
    {
        $out = [];
        foreach ($rows as $r => $row) {
            if ($r <= $header) {
                continue;
            }
            $cells = [];
            foreach ($columns as $field => $col) {
                $cells[$field] = self::text($row[$col] ?? null);
            }
            if (!array_filter($cells, fn ($v) => $v !== '')) {
                continue;
            }
            $item = ['row' => (int) $r, 'cells' => $cells];
            if ($kind === self::KIND_PARTICIPANTS) {
                $item['extra'] = [];
                foreach ($extra as $col => $title) {
                    $v = self::text($row[$col] ?? null);
                    if ($v !== '') {
                        $item['extra'][$title] = $v;
                    }
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v;
        }

        return EduText::squish((string) $v);
    }

    // ── run ──────────────────────────────────────────────────────────────

    /**
     * @param  array{resolutions?: array<string, array>, import_id?: int|null}  $options
     */
    public function run(EduProgram $program, array $parsed, array $options = [], bool $commit = false): array
    {
        $this->resolutions = $options['resolutions'] ?? [];
        $this->errors = $this->warnings = $this->needs = $this->rows = [];
        $this->summary = [];
        $kind = $parsed['kind'] ?? self::KIND_PARTICIPANTS;
        $this->sheetKey = $kind === self::KIND_COUNTS ? 'counts' : 'participants';
        $this->sheetName = (string) ($parsed['sheet'] ?? $this->sheetKey);
        $this->columnLetters = $parsed['columns'] ?? [];
        $this->matcher = new EduSchoolMatcher;
        $this->resolver = new EduMunicipalityResolver;

        DB::beginTransaction();
        try {
            $program = EduProgram::findOrFail($program->id);
            if ($kind === self::KIND_COUNTS) {
                $this->importCounts($program, $parsed);
            } else {
                $this->importParticipants($program, $parsed, $options['import_id'] ?? null);
            }

            $blocking = count($this->errors);
            $applied = $commit && $blocking === 0;
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
            'sheets' => [$this->sheetKey => $this->sheetName],
            'summary' => $this->summary,
            'totals' => $totals,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'needs_choice' => $this->needs,
            'rows' => $this->rows,
        ];
    }

    // ── participants ─────────────────────────────────────────────────────

    private function importParticipants(EduProgram $program, array $parsed, ?int $importId): void
    {
        $this->summary['participants'] = $this->counter() + ['missing' => 0, 'duplicates' => 0];
        $s = &$this->summary['participants'];

        $existing = EduParticipant::where('program_id', $program->id)->get();
        $byEmail = [];
        $bySchool = [];
        foreach ($existing as $p) {
            if ($p->email) {
                $byEmail[$p->email . '|' . $p->name_key] = $p;
            }
            if ($p->school_id) {
                $bySchool[$p->school_id . '|' . $p->name_key] ??= $p;
            }
        }
        $resolvedSchools = $this->resolvedSchools($program);
        $seen = [];
        $touched = [];
        $written = 0;

        foreach ($parsed['rows'] as $item) {
            $r = $item['row'];
            $ref = "participants:{$r}";
            $c = $item['cells'];
            $res = $this->resolutions[$ref] ?? [];
            $name = EduText::clean($c['full_name'] ?? '');
            if ($name === '') {
                $name = EduText::clean(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            }
            $view = [
                'ref' => $ref, 'row' => $r, 'full_name' => $name, 'school_text' => $c['school'] ?? '',
                'school_id' => null, 'school_name' => null, 'school_candidates' => [],
                'municipality_text' => $c['municipality'] ?? '', 'municipality_id' => null,
                'language' => null, 'email' => null, 'phone' => null, 'position' => ($c['position'] ?? '') ?: null,
                'status' => 'ok', 'action' => null, 'errors' => [], 'warnings' => [],
            ];

            if (!empty($res['skip'])) {
                $s['skipped']++;
                $this->rows[] = ['status' => 'skipped'] + $view;
                continue;
            }
            $s['rows']++;
            $rowErrors = 0;
            $err = function (string $column, string $code, string $message, ?string $value = null) use ($r, $ref, &$view, &$rowErrors) {
                $this->error($r, $this->column($column), $code, $message, $ref, $value);
                $view['errors'][] = $message;
                $rowErrors++;
            };
            $warn = function (string $column, string $code, string $message, ?string $value = null) use ($r, $ref, &$view) {
                $this->warn($r, $this->column($column), $code, $message, $ref, $value);
                $view['warnings'][] = $message;
            };

            if ($name === '') {
                $err('full_name', 'missing_name', 'Недостасува име и презиме.');
            }

            // Municipality (optional, narrows the school match).
            $municipality = null;
            if ($view['municipality_text'] !== '') {
                $municipality = $this->resolver->resolve($view['municipality_text'])['municipality'];
                if (!$municipality) {
                    $warn('municipality', 'unknown_municipality', "Непозната општина: „{$view['municipality_text']}“.", $view['municipality_text']);
                }
            }

            // School.
            $school = $this->school($program, $ref, $r, $view['school_text'], $municipality, $resolvedSchools, $view, $err, $warn);
            if ($school) {
                $view['school_id'] = $school->id;
                $view['school_name'] = $school->display_name;
                $municipality ??= $school->municipality;
            }
            $view['municipality_id'] = $school?->municipality_id ?? $municipality?->id;

            // Language: the cell, else the school's only known language.
            $language = $this->language($ref, $r, $c['language'] ?? '', $school, $res, $err);
            $view['language'] = $language;

            // E-mail and phone.
            $email = null;
            if (($c['email'] ?? '') !== '') {
                $parsedEmails = EduText::emails($c['email']);
                $email = $parsedEmails['emails'][0] ?? null;
                if (!$email) {
                    $warn('email', 'invalid_email', "Неважечка е-пошта: „{$c['email']}“.", $c['email']);
                } elseif (count($parsedEmails['emails']) > 1) {
                    $warn('email', 'several_emails', "Повеќе е-пошти во ќелијата; се зема „{$email}“.", $c['email']);
                }
            }
            $view['email'] = $email;
            $phone = null;
            if (($c['phone'] ?? '') !== '') {
                $phones = EduText::phones($c['phone']);
                $phone = implode(', ', array_merge($phones['phones'], $phones['director'])) ?: $c['phone'];
                $phone = mb_substr($phone, 0, 50);
            }
            $view['phone'] = $phone;

            if ($rowErrors > 0) {
                $view['status'] = 'error';
                $this->rows[] = $view;
                continue;
            }

            // Same person twice in the file: counted once.
            $key = EduText::personKey($name);
            $natural = $email ? "e|{$email}|{$key}" : "s|{$school->id}|{$key}";
            if (isset($seen[$natural])) {
                $warn('full_name', 'duplicate_row', "Истиот учесник е веќе во ред {$seen[$natural]}; редот се прескокнува.", $name);
                $s['duplicates']++;
                $s['skipped']++;
                $view['status'] = 'skipped';
                $this->rows[] = $view;
                continue;
            }
            $seen[$natural] = $r;

            $attributes = [
                'school_id' => $school->id,
                'school_text' => mb_substr($view['school_text'], 0, 255) ?: null,
                'municipality_id' => $view['municipality_id'],
                'language' => $language,
                'full_name' => mb_substr($name, 0, 150),
                'name_key' => mb_substr($key, 0, 150),
                'email' => $email ? mb_substr($email, 0, 150) : null,
                'phone' => $phone,
                'position' => $view['position'] ? mb_substr($view['position'], 0, 100) : null,
                'extra' => ($item['extra'] ?? []) ?: null,
            ];

            $participant = $email
                ? ($byEmail["{$email}|{$key}"] ?? (($p = $bySchool["{$school->id}|{$key}"] ?? null) && !$p->email ? $p : null))
                : ($bySchool["{$school->id}|{$key}"] ?? null);
            if ($participant && isset($touched[$participant->id])) {
                $participant = null;
            }

            if ($participant) {
                // An empty cell never erases what is already known.
                foreach (['school_text', 'municipality_id', 'email', 'phone', 'position', 'extra'] as $field) {
                    if ($attributes[$field] === null) {
                        unset($attributes[$field]);
                    }
                }
                $participant->fill($attributes);
                if ($participant->isDirty()) {
                    $participant->save();
                    $s['updated']++;
                    $view['action'] = 'update';
                    $written++;
                } else {
                    $s['unchanged']++;
                    $view['action'] = 'unchanged';
                }
            } else {
                $participant = EduParticipant::create($attributes + ['program_id' => $program->id, 'import_id' => $importId]);
                $s['created']++;
                $view['action'] = 'create';
                $written++;
            }
            $touched[$participant->id] = true;
            $this->rows[] = $view;
        }
        unset($s);

        $missing = $existing->reject(fn ($p) => isset($touched[$p->id]))->count();
        $this->summary['participants']['missing'] = $missing;
        if ($missing > 0) {
            $this->warnings[] = $this->item(null, null, 'not_in_file', "{$missing} постоечки учесници ги нема во датотеката; остануваат во програмата.", $this->sheetKey, (string) $missing);
        }

        // The program now works with persons; counts follow the participants.
        // (A file without a single valid person leaves a counts program alone.)
        if (!$program->isPersons() && $touched) {
            $program->update(['participant_mode' => EduProgram::MODE_PERSONS]);
            $this->counts->adoptAllocations($program);
        } elseif ($written > 0) {
            $this->counts->syncAll($program);
        }
    }

    // ── per-school counts ────────────────────────────────────────────────

    private function importCounts(EduProgram $program, array $parsed): void
    {
        $this->summary['schools'] = $this->counter() + ['missing' => 0];
        if ($program->isPersons()) {
            $this->error(null, null, 'persons_mode', 'Програмата има листа на учесници; бројките се пресметуваат од неа.', $this->sheetKey);

            return;
        }
        $resolvedSchools = $this->resolvedSchools($program);
        $aggregate = []; // school id => [counts, first row ref, rows]

        foreach ($parsed['rows'] as $item) {
            $r = $item['row'];
            $ref = "counts:{$r}";
            $c = $item['cells'];
            $res = $this->resolutions[$ref] ?? [];
            $schoolText = $c['school'] ?? '';
            // Total lines at the bottom of the sheet.
            if (preg_match('~^(вкупно|total|gjithsej)(\s|$)~u', EduText::words($schoolText))) {
                continue;
            }
            $view = [
                'ref' => $ref, 'row' => $r, 'school_text' => $schoolText, 'municipality_text' => $c['municipality'] ?? '',
                'school_id' => null, 'school_name' => null, 'school_candidates' => [],
                'count_mk' => 0, 'count_sq' => 0, 'count_tr' => 0, 'count_total' => 0,
                'status' => 'ok', 'action' => null, 'errors' => [], 'warnings' => [],
            ];
            if (!empty($res['skip'])) {
                $this->summary['schools']['skipped']++;
                $this->rows[] = ['status' => 'skipped'] + $view;
                continue;
            }
            $this->summary['schools']['rows']++;
            $rowErrors = 0;
            $err = function (string $column, string $code, string $message, ?string $value = null) use ($r, $ref, &$view, &$rowErrors) {
                $this->error($r, $this->column($column), $code, $message, $ref, $value);
                $view['errors'][] = $message;
                $rowErrors++;
            };
            $warn = function (string $column, string $code, string $message, ?string $value = null) use ($r, $ref, &$view) {
                $this->warn($r, $this->column($column), $code, $message, $ref, $value);
                $view['warnings'][] = $message;
            };

            $municipality = $view['municipality_text'] !== '' ? $this->resolver->resolve($view['municipality_text'])['municipality'] : null;
            $school = $this->school($program, $ref, $r, $schoolText, $municipality, $resolvedSchools, $view, $err, $warn);

            $counts = [];
            foreach (self::COUNT_FIELDS as $field) {
                $raw = $c[$field] ?? '';
                if ($raw === '' || $raw === '-') {
                    $counts[$field] = null;
                    continue;
                }
                if (!preg_match('~^\d+$~', $raw) || (int) $raw > 65535) {
                    $err($field, 'invalid_count', "Неважечка бројка: „{$raw}“.", $raw);
                    $counts[$field] = 0;
                    continue;
                }
                $counts[$field] = (int) $raw;
            }
            $byLanguage = ['mk' => (int) $counts['count_mk'], 'sq' => (int) $counts['count_sq'], 'tr' => (int) $counts['count_tr']];
            $sum = array_sum($byLanguage);
            $total = $counts['count_total'];

            // Only a total: it goes to the school's only language (or the chosen one).
            if ($sum === 0 && $total) {
                $language = $res['language'] ?? null;
                if (!$language && $school) {
                    $known = EduLanguage::normalize($school->languages);
                    $language = count($known) === 1 ? $known[0] : null;
                }
                if ($language) {
                    $byLanguage[$language] = $total;
                    $sum = $total;
                } else {
                    $err('count_total', 'unknown_language', 'Дадено е само вкупно, а јазикот на училиштето не е познат. Изберете јазик.', (string) $total);
                    $this->need($r, 'language', 'Изберете јазик за вкупниот број.', $ref, (string) $total, $this->languageCandidates($school), null);
                }
            } elseif ($total !== null && $total !== $sum) {
                $warn('count_total', 'total_mismatch', "Вкупно ({$total}) не е еднакво на збирот М+А+Т ({$sum}); се зема збирот.", (string) $total);
            }
            $view['count_mk'] = $byLanguage['mk'];
            $view['count_sq'] = $byLanguage['sq'];
            $view['count_tr'] = $byLanguage['tr'];
            $view['count_total'] = array_sum($byLanguage);
            if ($school) {
                $view['school_id'] = $school->id;
                $view['school_name'] = $school->display_name;
            }

            if ($rowErrors > 0) {
                $view['status'] = 'error';
                $this->rows[] = $view;
                continue;
            }
            if (isset($aggregate[$school->id])) {
                $warn('school', 'duplicate_school', "Училиштето е веќе во ред {$aggregate[$school->id]['row']}; бројките се собираат.", $schoolText);
                foreach ($byLanguage as $lang => $n) {
                    $aggregate[$school->id]['counts'][$lang] += $n;
                }
                $aggregate[$school->id]['views'][] = count($this->rows);
            } else {
                $aggregate[$school->id] = ['counts' => $byLanguage, 'row' => $r, 'ref' => $ref, 'views' => [count($this->rows)]];
            }
            $this->rows[] = $view;
        }

        $existing = EduProgramSchool::where('program_id', $program->id)->get()->keyBy('school_id');
        $allocated = $this->counts->allocated($program);
        foreach ($aggregate as $schoolId => $entry) {
            $short = [];
            foreach ($entry['counts'] as $lang => $n) {
                if (($allocated[$schoolId][$lang] ?? 0) > $n) {
                    $short[] = (EduLanguage::LABELS[$lang]) . ': ' . $allocated[$schoolId][$lang];
                }
            }
            if ($short) {
                $message = 'Во групите се веќе распоредени повеќе учесници (' . implode(', ', $short) . '). Прво намалете ги распределбите.';
                $this->error($entry['row'], $this->column('school'), 'below_allocated', $message, $entry['ref']);
                foreach ($entry['views'] as $i) {
                    $this->rows[$i]['status'] = 'error';
                    $this->rows[$i]['errors'][] = $message;
                }
                continue;
            }

            $row = $existing->get($schoolId) ?? new EduProgramSchool(['program_id' => $program->id, 'school_id' => $schoolId, 'source' => 'import']);
            $isNew = !$row->exists;
            $row->fill(['count_mk' => $entry['counts']['mk'], 'count_sq' => $entry['counts']['sq'], 'count_tr' => $entry['counts']['tr']]);
            $action = $isNew ? 'create' : ($row->isDirty() ? 'update' : 'unchanged');
            if ($row->isDirty() || $isNew) {
                $row->source = 'import';
                $row->save();
            }
            $this->summary['schools'][$action === 'create' ? 'created' : ($action === 'update' ? 'updated' : 'unchanged')]++;
            foreach ($entry['views'] as $i) {
                $this->rows[$i]['action'] = $action;
            }
        }

        $missing = $existing->keys()->diff(array_keys($aggregate))->count();
        $this->summary['schools']['missing'] = $missing;
        if ($missing > 0) {
            $this->warnings[] = $this->item(null, null, 'not_in_file', "{$missing} училишта од програмата ги нема во датотеката; нивните бројки не се менуваат.", $this->sheetKey, (string) $missing);
        }
    }

    // ── shared ───────────────────────────────────────────────────────────

    /** Schools the admin picked in resolutions, checked to be of the program's level. */
    private function resolvedSchools(EduProgram $program): array
    {
        $ids = collect($this->resolutions)->pluck('school_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        return $ids ? EduSchool::with('municipality:id,name')->whereIn('id', $ids)->where('level', $program->school_level)->get()->keyBy('id')->all() : [];
    }

    private function school(EduProgram $program, string $ref, int $r, string $text, $municipality, array $resolved, array &$view, callable $err, callable $warn): ?EduSchool
    {
        $res = $this->resolutions[$ref] ?? [];
        if (!empty($res['school_id'])) {
            $school = $resolved[(int) $res['school_id']] ?? null;
            if (!$school) {
                $err('school', 'invalid_school', 'Избраното училиште не постои или не е од нивото на програмата.', (string) $res['school_id']);
            }

            return $school;
        }
        if ($text === '') {
            $err('school', 'missing_school', 'Недостасува училиште.');

            return null;
        }
        $match = $this->matcher->match($text, $program->school_level, $municipality?->id, $municipality?->name);
        $view['school_candidates'] = $match['candidates'];
        if (!$match['school_id']) {
            $err('school', 'unknown_school', "Непознато училиште: „{$text}“. Изберете од понудените.", $text);
            $this->need($r, 'school_match', "Изберете го училиштето за „{$text}“.", $ref, $text, $match['candidates'], null);

            return null;
        }
        if ($match['method'] === 'fuzzy') {
            $first = collect($match['candidates'])->firstWhere('id', $match['school_id']);
            $warn('school', 'school_fuzzy', "Училиштето е препознаено приближно како „{$first['display_name']}“.", $text);
        }

        return EduSchool::with('municipality:id,name')->find($match['school_id']);
    }

    private function language(string $ref, int $r, string $cell, ?EduSchool $school, array $res, callable $err): ?string
    {
        if (!empty($res['language']) && EduLanguage::isValid($res['language'])) {
            return $res['language'];
        }
        $codes = EduText::language($cell);
        if (count($codes) === 1) {
            return $codes[0];
        }
        if (count($codes) > 1) {
            $err('language', 'mixed_language', "Повеќе јазици за еден учесник: „{$cell}“. Изберете еден.", $cell);
            $this->need($r, 'language', 'Изберете го јазикот на учесникот.', $ref, $cell, $this->languageCandidates(null, $codes), null);

            return null;
        }
        if ($cell !== '') {
            $err('language', 'unknown_language', "Непознат јазик: „{$cell}“.", $cell);
            $this->need($r, 'language', 'Изберете го јазикот на учесникот.', $ref, $cell, $this->languageCandidates($school), null);

            return null;
        }
        if ($school) {
            $known = EduLanguage::normalize($school->languages);
            if (count($known) === 1) {
                return $known[0];
            }
            $message = $known
                ? 'Јазикот е празен, а училиштето работи на повеќе јазици. Изберете јазик.'
                : 'Јазикот е празен, а јазикот на училиштето не е познат. Изберете јазик.';
            $err('language', 'unknown_language', $message);
            $this->need($r, 'language', 'Изберете го јазикот на учесникот.', $ref, null, $this->languageCandidates($school), null);
        }

        return null;
    }

    private function languageCandidates(?EduSchool $school, ?array $codes = null): array
    {
        $codes = $codes ?: (EduLanguage::normalize($school?->languages) ?: EduLanguage::CODES);

        return array_map(fn ($c) => ['code' => $c, 'label' => EduLanguage::LABELS[$c], 'name' => EduLanguage::NAMES[$c]], $codes);
    }

    private function column(string $field): ?string
    {
        return $this->columnLetters[$field] ?? null;
    }

    private function counter(): array
    {
        return ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0];
    }

    private function item(?int $row, ?string $column, string $code, string $message, ?string $ref, ?string $value): array
    {
        return [
            'ref' => $ref ?? ($row ? "{$this->sheetKey}:{$row}" : $this->sheetKey),
            'sheet' => $this->sheetName,
            'sheet_key' => $this->sheetKey,
            'row' => $row,
            'column' => $column,
            'code' => $code,
            'message' => $message,
            'value' => $value,
        ];
    }

    private function error(?int $row, ?string $column, string $code, string $message, ?string $ref = null, ?string $value = null): void
    {
        $this->errors[] = $this->item($row, $column, $code, $message, $ref, $value);
        foreach (array_keys($this->summary) as $entity) {
            $this->summary[$entity]['errors']++;
        }
    }

    private function warn(?int $row, ?string $column, string $code, string $message, ?string $ref = null, ?string $value = null): void
    {
        $this->warnings[] = $this->item($row, $column, $code, $message, $ref, $value);
    }

    private function need(int $row, string $kind, string $message, string $ref, ?string $value, array $candidates, mixed $default): void
    {
        $this->needs[] = [
            'ref' => $ref,
            'sheet' => $this->sheetName,
            'sheet_key' => $this->sheetKey,
            'row' => $row,
            'kind' => $kind,
            'message' => $message,
            'value' => $value,
            'candidates' => $candidates,
            'suggestion' => null,
            'default' => $default,
        ];
    }
}
