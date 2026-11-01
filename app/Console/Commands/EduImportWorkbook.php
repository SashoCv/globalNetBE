<?php

namespace App\Console\Commands;

use App\Models\EduTopic;
use App\Services\Edu\EduWorkbookImporter;
use Illuminate\Console\Command;

/**
 * Loads the client's master-data workbook (schools, trainers, topic lists,
 * РЕОНИ) without committing it to git. Dry run by default:
 *
 *   php artisan edu:import-workbook storage/app/private/edu/obuki_master.xlsx
 *   php artisan edu:import-workbook storage/app/private/edu/obuki_master.xlsx --apply
 */
class EduImportWorkbook extends Command
{
    protected $signature = 'edu:import-workbook
                                {path : Path to the .xlsx workbook}
                                {--sheets= : Comma-separated subset of: regions,secondary,primary,trainers,topics}
                                {--matrix-topic= : Topic id the trainer matrix applies to (default: every topic)}
                                {--resolutions= : JSON file with row choices, {"primary:15": {"municipality_id": 3}, …}}
                                {--apply : Write the changes (default is a dry run)}
                                {--skip-errors : With --apply, write the valid rows even if some rows have errors}
                                {--report= : Write the full result (errors, warnings, choices) to this JSON file}
                                {--warnings : Print every warning, not only the count}';

    protected $description = 'Import the Обуки master-data workbook (schools, trainers, topics, regions); dry run unless --apply';

    public function handle(EduWorkbookImporter $importer): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $sheets = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sheets')))));
        if ($bad = array_diff($sheets, EduWorkbookImporter::SHEETS)) {
            $this->error('Unknown sheet keys: ' . implode(', ', $bad) . '. Use: ' . implode(',', EduWorkbookImporter::SHEETS));

            return self::FAILURE;
        }

        $topicId = $this->option('matrix-topic') !== null ? (int) $this->option('matrix-topic') : null;
        if ($topicId && !EduTopic::whereKey($topicId)->exists()) {
            $this->error("Topic {$topicId} does not exist.");

            return self::FAILURE;
        }

        $resolutions = [];
        if ($file = $this->option('resolutions')) {
            $resolutions = json_decode((string) @file_get_contents($file), true);
            if (!is_array($resolutions)) {
                $this->error("Could not read resolutions JSON: {$file}");

                return self::FAILURE;
            }
        }

        $parsed = $importer->parse($path, $sheets);
        $result = $importer->run($parsed, [
            'matrix_topic_id' => $topicId,
            'resolutions' => $resolutions,
            'skip_errors' => (bool) $this->option('skip-errors'),
        ], (bool) $this->option('apply'));

        $this->line('Sheets: ' . collect($result['sheets'])->map(fn ($name, $key) => "{$key} = „{$name}“")->implode(', '));
        if ($parsed['ignored_sheets']) {
            $this->line('Ignored: ' . implode(', ', $parsed['ignored_sheets']));
        }
        $this->newLine();

        $this->table(
            ['', 'rows', 'created', 'updated', 'unchanged', 'skipped', 'errors'],
            collect($result['summary'])->map(fn ($s, $entity) => [
                $entity, $s['rows'], $s['created'], $s['updated'], $s['unchanged'], $s['skipped'], $s['errors'],
            ])->values()->all(),
        );
        if (isset($result['summary']['trainers'])) {
            $t = $result['summary']['trainers'];
            $this->line("Trainer mentions across sheets: {$t['mentions']}; trainers with unknown language: {$t['unknown_language']}; Albanian from a Latin-script name: {$t['language_from_script']}; topic links created: " . ($result['summary']['topics']['links_created'] ?? 0));
        }
        $defaulted = collect(['schools_secondary', 'schools_primary'])->sum(fn ($k) => $result['summary'][$k]['default_language'] ?? 0);
        if ($defaulted) {
            $this->line("Schools given the default language (" . config('edu.import.school_default_language') . ") for an empty cell: {$defaulted}");
        }

        if ($result['errors']) {
            $this->newLine();
            $this->error(count($result['errors']) . ' row(s) with errors (not imported):');
            foreach ($result['errors'] as $e) {
                $this->line("  [{$e['ref']}] {$e['sheet']}" . ($e['row'] ? " ред {$e['row']}" : '') . ($e['column'] ? " кол. {$e['column']}" : '') . ": {$e['message']}");
            }
        }

        if ($result['needs_choice']) {
            $this->newLine();
            $this->warn(count($result['needs_choice']) . ' row(s) need a choice (the default is used unless resolved):');
            foreach ($result['needs_choice'] as $n) {
                $candidates = collect($n['candidates'])->map(fn ($c) => ($c['label'] ?? $c['display_name'] ?? '?') . ' (' . ($c['score'] ?? '') . ')')->implode('; ');
                $this->line("  [{$n['ref']}] {$n['kind']}: {$n['message']}" . ($candidates ? " Кандидати: {$candidates}" : ''));
            }
        }

        $this->newLine();
        $byCode = collect($result['warnings'])->countBy('code');
        $this->line(count($result['warnings']) . ' warning(s)' . ($byCode->isNotEmpty() ? ': ' . $byCode->map(fn ($n, $code) => "{$code} {$n}")->implode(', ') : ''));
        if ($this->option('warnings')) {
            foreach ($result['warnings'] as $w) {
                $this->line("  [{$w['ref']}] {$w['code']}: {$w['message']}");
            }
        }

        if ($report = $this->option('report')) {
            file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line("Full result written to {$report}");
        }

        $this->newLine();
        $t = $result['totals'];
        $line = "created {$t['created']}, updated {$t['updated']}, unchanged {$t['unchanged']}, skipped {$t['skipped']}";
        if ($result['applied']) {
            $this->info("Applied: {$line}.");
        } elseif ($this->option('apply')) {
            $this->error("Nothing written: {$result['blocking_errors']} row(s) have errors. Fix them, resolve them with --resolutions, or pass --skip-errors.");

            return self::FAILURE;
        } else {
            $this->info("Dry run, nothing written ({$line}). Pass --apply to import.");
        }

        return self::SUCCESS;
    }
}
