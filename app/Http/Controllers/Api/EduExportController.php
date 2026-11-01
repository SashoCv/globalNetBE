<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduProgram;
use App\Services\Edu\DynamicsExporter;
use App\Services\Edu\EduGroupOutput;
use App\Services\Edu\GroupDocumentBuilder;
use App\Services\Edu\GroupDocxRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Обуки: the outputs of a program — the "Динамика" Excel (step 10) and the
 * printable group documents (step 11) as PDF or Word. ?bilingual=0|1 forces
 * the "мк / sq" labels off / on; by default (auto) Albanian groups get them.
 */
class EduExportController extends Controller
{
    public function __construct(
        private EduGroupOutput $output,
        private DynamicsExporter $dynamics,
        private GroupDocumentBuilder $documents,
        private GroupDocxRenderer $docx,
    ) {
    }

    // GET /api/edu-programs/{id}/dynamics.xlsx?format=standard|host (alias layout) &group_ids[]&status[]&with_title=1
    public function dynamics(Request $request, int $id): StreamedResponse
    {
        $program = EduProgram::findOrFail($id);
        $request->validate([
            'format' => ['sometimes', Rule::in(DynamicsExporter::FORMATS)],
            'layout' => ['sometimes', Rule::in(DynamicsExporter::FORMATS)],
            'with_title' => 'sometimes|boolean',
        ] + $this->groupRules());

        $format = $request->input('format', $request->input('layout', 'standard'));
        $groups = $this->output->groups($program, $this->groupIds($request), $this->statuses($request));
        $book = $this->dynamics->spreadsheet($program, $groups, $format, $request->boolean('with_title'));
        $writer = $this->dynamics->writer($book);

        return response()->streamDownload(function () use ($writer, $book) {
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $this->dynamics->filename($program, $format), [
            'Content-Type' => DynamicsExporter::MIME,
            'Cache-Control' => 'no-store',
        ]);
    }

    // GET /api/edu-groups/{id}/document.pdf?signatures=1&blank_rows=1&bilingual=auto|0|1
    public function groupPdf(Request $request, int $id): Response
    {
        $group = EduGroup::findOrFail($id);
        $program = EduProgram::findOrFail($group->program_id);
        $request->validate($this->documentRules());

        $data = $this->documents->build($group, $program, $this->documentOptions($request));

        return Pdf::loadView('pdf.edu-group', ['group' => $data, 'printed_at' => now()->format('d.m.Y')])
            ->setPaper('a4')
            ->download($this->documents->filename($group, $program));
    }

    // GET /api/edu-programs/{id}/groups/documents.pdf?group_ids[]&status[]&signatures=1&blank_rows=1&bilingual=auto|0|1
    public function groupsPdf(Request $request, int $id): Response
    {
        $program = EduProgram::findOrFail($id);
        $request->validate($this->documentRules() + $this->groupRules());

        $groups = $this->output->groups($program, $this->groupIds($request), $this->statuses($request));
        $data = $this->documents->buildMany($groups, $program, $this->documentOptions($request));

        // dompdf keeps the whole document in memory: ~190MB for the demo
        // program's 39 groups / 850 participants with blank rows.
        $this->raiseLimits(count($data));

        return Pdf::loadView('pdf.edu-groups', [
            'groups' => $data,
            'title' => $program->title,
            'printed_at' => now()->format('d.m.Y'),
        ])->setPaper('a4')->download($this->documents->filenameMany($program));
    }

    // GET /api/edu-groups/{id}/document.docx — same parameters as document.pdf
    public function groupDocx(Request $request, int $id): StreamedResponse
    {
        $group = EduGroup::findOrFail($id);
        $program = EduProgram::findOrFail($group->program_id);
        $request->validate($this->documentRules());

        $data = $this->documents->build($group, $program, $this->documentOptions($request));

        return $this->docxDownload([$data], $program->title, $this->documents->filename($group, $program, 'docx'));
    }

    // GET /api/edu-programs/{id}/groups/documents.docx — same parameters as documents.pdf
    public function groupsDocx(Request $request, int $id): StreamedResponse
    {
        $program = EduProgram::findOrFail($id);
        $request->validate($this->documentRules() + $this->groupRules());

        $groups = $this->output->groups($program, $this->groupIds($request), $this->statuses($request));
        $data = $this->documents->buildMany($groups, $program, $this->documentOptions($request));
        $this->raiseLimits(count($data));

        return $this->docxDownload($data, $program->title, $this->documents->filenameMany($program, 'docx'));
    }

    private function docxDownload(array $data, ?string $title, string $filename): StreamedResponse
    {
        $writer = $this->docx->writer($this->docx->document($data, $title, now()->format('d.m.Y')));

        return response()->streamDownload(fn () => $writer->save('php://output'), $filename, [
            'Content-Type' => GroupDocxRenderer::MIME,
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Raises (never lowers) memory_limit to 512M plus 8M per group over 40, and the time limit. */
    private function raiseLimits(int $groups): void
    {
        $wanted = (512 + max(0, $groups - 40) * 8) * 1024 * 1024;
        $current = ini_get('memory_limit');
        $bytes = $current === '-1' ? PHP_INT_MAX : ini_parse_quantity((string) $current);
        if ($bytes < $wanted) {
            ini_set('memory_limit', (string) $wanted);
        }
        $seconds = max(120, $groups * 3);
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < $seconds && function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    private function groupRules(): array
    {
        return [
            'group_ids' => 'sometimes|array',
            'group_ids.*' => 'integer',
            'status' => 'sometimes',
            'status.*' => Rule::in(EduGroup::STATUSES),
        ];
    }

    /** @return list<int>|null */
    private function groupIds(Request $request): ?array
    {
        $ids = array_filter((array) $request->input('group_ids', []), fn ($v) => $v !== null && $v !== '');

        return $ids ? array_map('intval', array_values($ids)) : null;
    }

    /** ?status=planned, ?status=planned,held or ?status[]=planned; unknown values → 422. */
    private function statuses(Request $request): ?array
    {
        $raw = $request->input('status');
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $statuses = array_values(array_filter(array_map('trim', is_array($raw) ? $raw : explode(',', (string) $raw))));
        validator(['status' => $statuses], ['status.*' => Rule::in(EduGroup::STATUSES)])->validate();

        return $statuses ?: null;
    }

    private function documentRules(): array
    {
        return [
            'signatures' => 'sometimes|boolean',
            'blank_rows' => 'sometimes|boolean',
            'bilingual' => ['sometimes', 'nullable', Rule::in(['auto', '0', '1', 0, 1, true, false, 'true', 'false'])],
        ];
    }

    /** @return array{signatures: bool, blank_rows: bool, bilingual: ?bool} */
    private function documentOptions(Request $request): array
    {
        $bilingual = $request->input('bilingual');

        return [
            'signatures' => $request->boolean('signatures', true),
            'blank_rows' => $request->boolean('blank_rows', true),
            // null = auto: Albanian groups get "мк / sq" labels.
            'bilingual' => $bilingual === null || $bilingual === '' || $bilingual === 'auto' ? null : $request->boolean('bilingual'),
        ];
    }
}
