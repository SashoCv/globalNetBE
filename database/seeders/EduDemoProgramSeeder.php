<?php

namespace Database\Seeders;

use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduSchool;
use App\Services\Edu\EduMunicipalityResolver;
use App\Services\Edu\EduSchoolMatcher;
use App\Services\Edu\EduText;
use App\Services\Edu\ProgramZoneService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Обуки — dev only: a primary-school program "Стручни соработници" (11–25)
 * with the per-school counts rebuilt from column C of the client's ПРИМЕР
 * ДИНАМИКА sheet ("School-Municipality (n)", 38 hand-made groups, 865
 * participants), to compare automatic grouping with the real thing.
 *
 * Needs the workbook in storage/app/private/edu/obuki_master.xlsx (not in
 * git) and the master data imported first (edu:import-workbook --apply).
 * Idempotent: the program is found by its title and its counts are reset to
 * what the sheet says. Run by hand:
 *
 *   php artisan db:seed --class=EduDemoProgramSeeder
 */
class EduDemoProgramSeeder extends Seeder
{
    public const TITLE = 'ДЕМО: Обука за стручни соработници во основните училишта (ПРИМЕР ДИНАМИКА)';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->say('warn', 'EduDemoProgramSeeder is for development only.');

            return;
        }
        $path = storage_path('app/private/edu/obuki_master.xlsx');
        if (!is_file($path)) {
            $this->say('warn', "Workbook not found: {$path}");

            return;
        }
        if (!EduSchool::where('level', EduSchool::LEVEL_PRIMARY)->exists()) {
            $this->say('warn', 'No primary schools yet — run edu:import-workbook --apply first.');

            return;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheetByName('ПРИМЕР ДИНАМИКА');
        if (!$sheet) {
            $this->say('warn', 'Sheet "ПРИМЕР ДИНАМИКА" not found.');

            return;
        }

        $resolver = new EduMunicipalityResolver;
        $matcher = new EduSchoolMatcher;
        $counts = [];
        $unmatched = [];
        $entries = 0;
        $total = 0;
        foreach ($sheet->toArray(null, true, true, true) as $r => $row) {
            $text = EduText::squish((string) ($row['C'] ?? ''));
            if ($r < 2 || $text === '') {
                continue;
            }
            $groupLanguage = EduText::language((string) ($row['F'] ?? ''))[0] ?? 'mk';
            preg_match_all('~(.+?)\(\s*([^)]*?)\s*\)~u', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, $spec]) {
                $entries++;
                $name = trim($name, " ,;)\t");
                $municipalityText = null;
                if (($dash = mb_strrpos($name, '-')) !== false) {
                    $municipalityText = trim(mb_substr($name, $dash + 1));
                    $schoolText = trim(mb_substr($name, 0, $dash));
                } else {
                    $schoolText = $name;
                }
                $municipality = $municipalityText ? $resolver->resolve($municipalityText)['municipality'] : null;
                $match = $matcher->match($schoolText, EduSchool::LEVEL_PRIMARY, $municipality?->id, $municipality?->name);
                $schoolId = $match['school_id'] ?? null;
                if (!$schoolId && $municipality) {
                    // The name sometimes repeats the village or town: try the whole level.
                    $schoolId = $matcher->match($schoolText, EduSchool::LEVEL_PRIMARY)['school_id'];
                }
                // "(1+1т)": counts per language; no suffix = the group's language.
                foreach (preg_split('~\+~u', $spec) ?: [] as $token) {
                    if (!preg_match('~(\d+)\s*([атм]?)~u', $token, $t)) {
                        continue;
                    }
                    $language = match ($t[2]) {
                        'т' => 'tr',
                        'а' => 'sq',
                        'м' => 'mk',
                        default => $groupLanguage,
                    };
                    $n = (int) $t[1];
                    $total += $n;
                    if (!$schoolId) {
                        $unmatched[] = "row {$r}: {$name} ({$spec})";
                        continue 2;
                    }
                    $counts[$schoolId][$language] = ($counts[$schoolId][$language] ?? 0) + $n;
                }
            }
        }

        DB::transaction(function () use ($counts) {
            $program = EduProgram::firstWhere('title', self::TITLE);
            if (!$program) {
                $program = EduProgram::create([
                    'title' => self::TITLE,
                    'school_level' => EduSchool::LEVEL_PRIMARY,
                    'staff_type' => 'professional_associates',
                    'group_size_min' => 11,
                    'group_size_max' => 25,
                    'period_label' => 'мај–јуни 2026',
                    'default_start_time' => '08:30',
                    'default_end_time' => '13:30',
                    'default_days' => 2,
                ]);
                app(ProgramZoneService::class)->initFromRegions($program);
            }
            if ($program->isPersons()) {
                return;
            }
            foreach ($counts as $schoolId => $byLanguage) {
                EduProgramSchool::updateOrCreate(
                    ['program_id' => $program->id, 'school_id' => $schoolId],
                    ['count_mk' => $byLanguage['mk'] ?? 0, 'count_sq' => $byLanguage['sq'] ?? 0, 'count_tr' => $byLanguage['tr'] ?? 0, 'source' => 'import'],
                );
            }
        });

        $program = EduProgram::firstWhere('title', self::TITLE);
        $loaded = (int) EduProgramSchool::where('program_id', $program->id)->sum('count_total');
        $this->say('info', "Program #{$program->id}: {$entries} entries in ПРИМЕР ДИНАМИКА, {$total} participants, "
            . count($counts) . " schools matched, {$loaded} participants loaded.");
        foreach ($unmatched as $line) {
            $this->say('warn', "Not matched: {$line}");
        }
    }

    private function say(string $level, string $message): void
    {
        $this->command?->{$level}($message);
    }
}
