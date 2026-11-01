<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduImport;
use App\Models\EduProgram;
use App\Services\Edu\EduParticipantImporter;
use App\Services\Edu\EduWorkbookImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Обуки: Excel imports — upload (parse + preview) → resolve rows → apply.
 *
 * The uploaded file and a preview JSON (the parsed cells) are kept on the
 * local disk under edu-imports/. Every preview and the apply re-run the
 * import against the current database, so what the preview shows is what
 * applying does.
 */
class EduImportController extends Controller
{
    public function __construct(
        private EduWorkbookImporter $importer,
        private EduParticipantImporter $participantImporter,
    ) {
    }

    // POST /api/edu-imports/workbook  (multipart: file, sheets[], matrix_topic_id)
    public function workbook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
            'sheets' => 'nullable|array',
            'sheets.*' => 'in:' . implode(',', EduWorkbookImporter::SHEETS),
            'matrix_topic_id' => 'nullable|integer|exists:edu_topics,id',
        ]);

        $disk = Storage::disk(config('edu.imports_disk', 'local'));
        $dir = config('edu.imports_dir', 'edu-imports');
        $file = $request->file('file');
        $path = $file->storeAs($dir, Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: 'xlsx'), config('edu.imports_disk', 'local'));

        $import = EduImport::create([
            'type' => EduImport::TYPE_WORKBOOK,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => EduImport::STATUS_PREVIEWED,
            'options' => [
                'sheets' => $data['sheets'] ?? [],
                'matrix_topic_id' => isset($data['matrix_topic_id']) ? (int) $data['matrix_topic_id'] : null,
                'resolutions' => (object) [],
            ],
            'created_by' => $request->user()?->id,
        ]);

        try {
            $parsed = $this->importer->parse($disk->path($path), $data['sheets'] ?? []);
        } catch (Throwable $e) {
            report($e);
            $import->update(['status' => EduImport::STATUS_FAILED, 'summary' => ['error' => 'Датотеката не може да се прочита.']]);

            return response()->json(['message' => 'Датотеката не може да се прочита како Excel работна книга.', 'import_id' => $import->id], 422);
        }

        if (!array_filter($parsed['sheets'])) {
            $import->update(['status' => EduImport::STATUS_FAILED, 'summary' => ['error' => 'Нема препознаени листови.']]);

            return response()->json([
                'message' => 'Во датотеката нема препознаени листови (училишта, обучувачи, теми или РЕОНИ).',
                'import_id' => $import->id,
                'ignored_sheets' => $parsed['ignored_sheets'],
            ], 422);
        }

        $previewPath = "{$dir}/{$import->id}.preview.json";
        $disk->put($previewPath, json_encode(['parsed' => $parsed], JSON_UNESCAPED_UNICODE));
        $import->update(['preview_path' => $previewPath]);

        $result = $this->preview($import, $parsed);

        return response()->json($this->payload($import, $result, $parsed), 201);
    }

    // POST /api/edu-programs/{id}/participants/import  (multipart: file, sheet?, header_row?)
    public function participants(Request $request, int $id): JsonResponse
    {
        return $this->programImport($request, $id, EduImport::TYPE_PARTICIPANTS);
    }

    // POST /api/edu-programs/{id}/school-counts/import  (multipart: file, sheet?, header_row?)
    public function schoolCounts(Request $request, int $id): JsonResponse
    {
        return $this->programImport($request, $id, EduImport::TYPE_SCHOOL_COUNTS);
    }

    // GET /api/edu-imports/{id}
    public function show(int $id): JsonResponse
    {
        $import = EduImport::findOrFail($id);
        $preview = $this->readPreview($import);
        if (!$preview) {
            return response()->json($this->payload($import, null, null));
        }

        return response()->json($this->payload($import, $preview['result'] ?? null, $preview['parsed']));
    }

    // PUT /api/edu-imports/{id}/rows  body: { rows: [{ref, school_id?, municipality_id?, trainer_id?, merge_ref?, languages?, language?, create?, skip?}] }
    public function resolveRows(Request $request, int $id): JsonResponse
    {
        $import = EduImport::findOrFail($id);
        if ($import->status !== EduImport::STATUS_PREVIEWED) {
            return response()->json(['message' => 'Увозот е веќе ' . $this->statusLabel($import->status) . '.'], 409);
        }
        $data = $request->validate([
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.ref' => 'required|string|max:50',
            'rows.*.school_id' => 'nullable|integer',
            'rows.*.municipality_id' => 'nullable|integer',
            'rows.*.home_municipality_id' => 'nullable|integer',
            'rows.*.trainer_id' => 'nullable|integer',
            'rows.*.merge_ref' => 'nullable|string|max:50',
            'rows.*.languages' => 'nullable|array',
            'rows.*.languages.*' => 'in:mk,sq,tr',
            'rows.*.language' => 'nullable|in:mk,sq,tr',
            'rows.*.create' => 'nullable|boolean',
            'rows.*.skip' => 'nullable|boolean',
            'rows.*.reset' => 'nullable|boolean',
        ]);

        $preview = $this->readPreview($import);
        if (!$preview) {
            return response()->json(['message' => 'Прегледот на увозот не постои повеќе. Прикачете ја датотеката повторно.'], 409);
        }

        $options = $import->options ?? [];
        $resolutions = (array) ($options['resolutions'] ?? []);
        foreach ($data['rows'] as $row) {
            $ref = $row['ref'];
            if (!empty($row['reset'])) {
                unset($resolutions[$ref]);
                continue;
            }
            $choice = collect($row)->except(['ref', 'reset'])->filter(fn ($v) => $v !== null)->all();
            if (array_key_exists('languages', $row) && $row['languages'] !== null) {
                $choice['languages'] = array_values($row['languages']);
            }
            // Each row replaces the earlier choice for that ref; an empty one clears it.
            if ($choice) {
                $resolutions[$ref] = $choice;
            } else {
                unset($resolutions[$ref]);
            }
        }
        $options['resolutions'] = $resolutions ?: (object) [];
        $import->update(['options' => $options]);

        $result = $this->preview($import, $preview['parsed']);

        return response()->json($this->payload($import, $result, $preview['parsed']));
    }

    // POST /api/edu-imports/{id}/apply
    public function apply(Request $request, int $id): JsonResponse
    {
        $import = EduImport::findOrFail($id);
        if ($import->status !== EduImport::STATUS_PREVIEWED) {
            return response()->json(['message' => 'Увозот е веќе ' . $this->statusLabel($import->status) . '.'], 409);
        }
        $preview = $this->readPreview($import);
        if (!$preview) {
            return response()->json(['message' => 'Прегледот на увозот не постои повеќе. Прикачете ја датотеката повторно.'], 409);
        }

        $result = $this->runImport($import, $preview['parsed'], true);
        $this->storeResult($import, $preview['parsed'], $result);

        if (!$result['applied']) {
            return response()->json([
                'message' => "Увозот не е применет: {$result['blocking_errors']} редови имаат грешки. Поправете ги или прескокнете ги.",
                'summary' => $result['summary'],
                'errors' => $result['errors'],
            ], 422);
        }

        $import->update(['status' => EduImport::STATUS_APPLIED, 'applied_at' => now()]);

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'created' => $result['totals']['created'],
            'updated' => $result['totals']['updated'],
            'unchanged' => $result['totals']['unchanged'],
            'skipped' => $result['totals']['skipped'],
            'summary' => $result['summary'],
        ]);
    }

    // DELETE /api/edu-imports/{id}  — discard: deletes the files, keeps the log row.
    public function destroy(int $id): JsonResponse
    {
        $import = EduImport::findOrFail($id);
        $disk = Storage::disk(config('edu.imports_disk', 'local'));
        foreach (array_filter([$import->path, $import->preview_path]) as $path) {
            $disk->delete($path);
        }
        $import->update([
            'status' => $import->status === EduImport::STATUS_APPLIED ? EduImport::STATUS_APPLIED : EduImport::STATUS_DISCARDED,
            'path' => null,
            'preview_path' => null,
        ]);

        return response()->json(null, 204);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Participant list or per-school counts of a program: store the file,
     * find the sheet and header, preview.
     */
    private function programImport(Request $request, int $programId, string $type): JsonResponse
    {
        $program = EduProgram::findOrFail($programId);
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:20480',
            'sheet' => 'nullable|string|max:100',
            'header_row' => 'nullable|integer|min:1|max:200',
        ]);
        if ($type === EduImport::TYPE_SCHOOL_COUNTS && $program->isPersons()) {
            return response()->json(['message' => 'Програмата има листа на учесници; бројките по училиште се пресметуваат од неа.'], 409);
        }

        $disk = Storage::disk(config('edu.imports_disk', 'local'));
        $dir = config('edu.imports_dir', 'edu-imports');
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $extension = in_array($extension, ['xlsx', 'xls', 'csv'], true) ? $extension : 'csv';
        $path = $file->storeAs($dir, Str::uuid() . '.' . $extension, config('edu.imports_disk', 'local'));

        $import = EduImport::create([
            'program_id' => $program->id,
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => EduImport::STATUS_PREVIEWED,
            'options' => [
                'sheet' => $data['sheet'] ?? null,
                'header_row' => isset($data['header_row']) ? (int) $data['header_row'] : null,
                'resolutions' => (object) [],
            ],
            'created_by' => $request->user()?->id,
        ]);

        try {
            $parsed = $this->participantImporter->parse($disk->path($path), $type, $data['sheet'] ?? null, isset($data['header_row']) ? (int) $data['header_row'] : null);
        } catch (Throwable $e) {
            report($e);
            $import->update(['status' => EduImport::STATUS_FAILED, 'summary' => ['error' => 'Датотеката не може да се прочита.']]);

            return response()->json(['message' => 'Датотеката не може да се прочита како Excel или CSV табела.', 'import_id' => $import->id], 422);
        }

        if ($parsed['header_row'] === null) {
            $import->update(['status' => EduImport::STATUS_FAILED, 'summary' => ['error' => 'Не е пронајдено заглавие.']]);
            $expected = $type === EduImport::TYPE_SCHOOL_COUNTS
                ? 'колона за училиште и барем една од М, А, Т, Вк'
                : 'колони за име и презиме, училиште и барем уште една (јазик, општина, е-пошта…)';

            return response()->json([
                'message' => isset($data['sheet']) && !in_array($data['sheet'], $parsed['sheet_names'], true)
                    ? "Листот „{$data['sheet']}“ не постои во датотеката."
                    : "Не е пронајдено заглавие: очекувани се {$expected}.",
                'import_id' => $import->id,
                'sheets' => $parsed['sheet_names'],
            ], 422);
        }

        $previewPath = "{$dir}/{$import->id}.preview.json";
        $disk->put($previewPath, json_encode(['parsed' => $parsed], JSON_UNESCAPED_UNICODE));
        $import->update(['preview_path' => $previewPath]);

        $result = $this->preview($import, $parsed);

        return response()->json($this->payload($import, $result, $parsed), 201);
    }

    private function runImport(EduImport $import, array $parsed, bool $commit): array
    {
        if ($import->type === EduImport::TYPE_WORKBOOK) {
            return $this->importer->run($parsed, $this->runOptions($import), $commit);
        }

        return $this->participantImporter->run(
            EduProgram::findOrFail($import->program_id),
            $parsed,
            ['resolutions' => (array) ($import->options['resolutions'] ?? []), 'import_id' => $import->id],
            $commit,
        );
    }

    private function preview(EduImport $import, array $parsed): array
    {
        $result = $this->runImport($import, $parsed, false);
        $this->storeResult($import, $parsed, $result);

        return $result;
    }

    private function runOptions(EduImport $import): array
    {
        $options = $import->options ?? [];

        return [
            'matrix_topic_id' => $options['matrix_topic_id'] ?? null,
            'resolutions' => (array) ($options['resolutions'] ?? []),
        ];
    }

    private function storeResult(EduImport $import, array $parsed, array $result): void
    {
        $disk = Storage::disk(config('edu.imports_disk', 'local'));
        if ($import->preview_path) {
            $disk->put($import->preview_path, json_encode(['parsed' => $parsed, 'result' => $result], JSON_UNESCAPED_UNICODE));
        }
        $import->update(['summary' => $result['summary'] + [
            'totals' => $result['totals'],
            'errors_count' => count($result['errors']),
            'warnings_count' => count($result['warnings']),
            'needs_choice_count' => count($result['needs_choice']),
        ]]);
    }

    private function readPreview(EduImport $import): ?array
    {
        $disk = Storage::disk(config('edu.imports_disk', 'local'));
        if (!$import->preview_path || !$disk->exists($import->preview_path)) {
            return null;
        }
        $data = json_decode((string) $disk->get($import->preview_path), true);

        return is_array($data) && isset($data['parsed']) ? $data : null;
    }

    private function payload(EduImport $import, ?array $result, ?array $parsed): array
    {
        $payload = [
            'import_id' => $import->id,
            'type' => $import->type,
            'status' => $import->status,
            'original_name' => $import->original_name,
            'sheets' => $parsed['sheets'] ?? [],
            'ignored_sheets' => $parsed['ignored_sheets'] ?? [],
            'summary' => $result['summary'] ?? $import->summary,
            'totals' => $result['totals'] ?? null,
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'needs_choice' => $result['needs_choice'] ?? [],
            'resolutions' => (object) ($import->options['resolutions'] ?? []),
            'can_apply' => $import->status === EduImport::STATUS_PREVIEWED && $result !== null && $result['blocking_errors'] === 0,
            'created_at' => $import->created_at,
            'applied_at' => $import->applied_at,
        ];
        if ($import->type === EduImport::TYPE_WORKBOOK) {
            return $payload;
        }

        // Participant / count imports: the sheet that was read and every row.
        $columns = [];
        foreach ($parsed['columns'] ?? [] as $field => $column) {
            $columns[$field] = ['column' => $column, 'header' => $parsed['headers'][$column] ?? null];
        }

        return $payload + [
            'program_id' => $import->program_id,
            'sheet' => $parsed['sheet'] ?? null,
            'available_sheets' => $parsed['sheet_names'] ?? [],
            'header_row' => $parsed['header_row'] ?? null,
            'detected_columns' => (object) $columns,
            'extra_columns' => array_values($parsed['extra_columns'] ?? []),
            'rows' => $result['rows'] ?? [],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            EduImport::STATUS_APPLIED => 'применет',
            EduImport::STATUS_DISCARDED => 'отфрлен',
            EduImport::STATUS_FAILED => 'неуспешен',
            default => $status,
        };
    }
}
