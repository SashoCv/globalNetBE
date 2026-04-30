<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EventSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EvaluationController extends Controller
{
    private const TYPES = ['text', 'textarea', 'radio', 'checkbox', 'rating', 'yes_no'];
    private const ANON_MODES = ['anonymous', 'identified', 'both'];

    // GET /api/event-sessions/{id}/evaluations
    public function index(int $sessionId): JsonResponse
    {
        EventSession::findOrFail($sessionId);

        $evaluations = Evaluation::where('event_session_id', $sessionId)
            ->withCount(['questions', 'responses'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($evaluations);
    }

    // POST /api/event-sessions/{id}/evaluations
    public function store(Request $request, int $sessionId): JsonResponse
    {
        EventSession::findOrFail($sessionId);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'anonymity_mode' => 'nullable|string|in:' . implode(',', self::ANON_MODES),
            'redirect_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['event_session_id'] = $sessionId;

        $evaluation = Evaluation::create($validated);
        $evaluation->loadCount(['questions', 'responses']);

        return response()->json($evaluation, 201);
    }

    // PUT /api/evaluations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'anonymity_mode' => 'sometimes|string|in:' . implode(',', self::ANON_MODES),
            'redirect_url' => 'nullable|url|max:500',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $evaluation->update($validated);
        $evaluation->loadCount(['questions', 'responses']);

        return response()->json($evaluation);
    }

    // DELETE /api/evaluations/{id}
    public function destroy(int $id): JsonResponse
    {
        Evaluation::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // GET /api/evaluations/{id}/questions
    public function questions(int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);
        return response()->json($evaluation->questions()->get());
    }

    // POST /api/evaluations/{id}/questions
    public function storeQuestion(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $validated = $request->validate([
            'question_text' => 'required|string',
            'type' => 'required|string|in:' . implode(',', self::TYPES),
            'options' => 'nullable|array',
            'required' => 'nullable|boolean',
            'min_selections' => 'nullable|integer|min:0',
            'max_selections' => 'nullable|integer|min:1',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['evaluation_id'] = $evaluation->id;

        // Default sort_order = max + 1
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) $evaluation->questions()->max('sort_order') + 1;
        }

        $question = EvaluationQuestion::create($validated);

        return response()->json($question, 201);
    }

    // PUT /api/evaluation-questions/{id}
    public function updateQuestion(Request $request, int $id): JsonResponse
    {
        $question = EvaluationQuestion::findOrFail($id);

        $validated = $request->validate([
            'question_text' => 'sometimes|string',
            'type' => 'sometimes|string|in:' . implode(',', self::TYPES),
            'options' => 'nullable|array',
            'required' => 'sometimes|boolean',
            'min_selections' => 'nullable|integer|min:0',
            'max_selections' => 'nullable|integer|min:1',
            'sort_order' => 'sometimes|integer',
        ]);

        $question->update($validated);

        return response()->json($question);
    }

    // DELETE /api/evaluation-questions/{id}
    public function destroyQuestion(int $id): JsonResponse
    {
        EvaluationQuestion::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // GET /api/evaluations/{id}/responses
    public function responses(int $id): JsonResponse
    {
        $evaluation = Evaluation::with('questions:id,evaluation_id,question_text,type,sort_order')->findOrFail($id);

        $responses = $evaluation->responses()
            ->with('answers:id,evaluation_response_id,evaluation_question_id,answer_value')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'questions' => $evaluation->questions,
            'responses' => $responses,
        ]);
    }

    // GET /api/evaluations/{id}/stats
    public function stats(int $id): JsonResponse
    {
        $evaluation = Evaluation::with('questions')->findOrFail($id);

        $totalResponses = $evaluation->responses()->count();
        $anonymousCount = $evaluation->responses()->where('is_anonymous', true)->count();

        $perQuestion = [];

        foreach ($evaluation->questions as $question) {
            // Pull answers with their response (for identity)
            $answersWithResponse = $question->answers()->with('response:id,first_name,last_name,email,is_anonymous')->get();
            $values = $answersWithResponse->pluck('answer_value')->all();

            $stat = [
                'question_id' => $question->id,
                'question_text' => $question->question_text,
                'type' => $question->type,
                'total_answers' => count($values),
            ];

            if (in_array($question->type, ['radio', 'checkbox'], true)) {
                $counts = [];
                foreach ($question->options ?? [] as $opt) {
                    $counts[$opt] = 0;
                }
                foreach ($values as $value) {
                    if ($question->type === 'checkbox') {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $v) {
                                if (isset($counts[$v])) $counts[$v]++;
                                else $counts[$v] = 1;
                            }
                        }
                    } else {
                        if (isset($counts[$value])) $counts[$value]++;
                        else $counts[$value] = 1;
                    }
                }
                $stat['option_counts'] = $counts;
            } elseif ($question->type === 'rating') {
                $nums = array_map('floatval', array_filter($values, fn($v) => is_numeric($v)));
                $scale = (int) ($question->options['scale'] ?? 5);
                $distribution = array_fill(1, $scale, 0);
                foreach ($nums as $n) {
                    $bucket = max(1, min($scale, (int) round($n)));
                    $distribution[$bucket]++;
                }
                $stat['scale'] = $scale;
                $stat['average'] = count($nums) > 0 ? round(array_sum($nums) / count($nums), 2) : null;
                $stat['distribution'] = $distribution;
            } else {
                // text / textarea — keep identity
                $stat['answers'] = $answersWithResponse
                    ->filter(fn($a) => $a->answer_value !== null && $a->answer_value !== '')
                    ->map(function ($a) {
                        $r = $a->response;
                        return [
                            'value' => $a->answer_value,
                            'is_anonymous' => $r ? (bool) $r->is_anonymous : true,
                            'name' => ($r && !$r->is_anonymous)
                                ? trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''))
                                : null,
                        ];
                    })
                    ->values()
                    ->all();
            }

            $perQuestion[] = $stat;
        }

        return response()->json([
            'evaluation' => [
                'id' => $evaluation->id,
                'title' => $evaluation->title,
                'anonymity_mode' => $evaluation->anonymity_mode,
            ],
            'total_responses' => $totalResponses,
            'anonymous_count' => $anonymousCount,
            'questions' => $perQuestion,
        ]);
    }

    // GET /api/evaluations/{id}/report.pdf
    public function reportPdf(int $id): Response
    {
        $evaluation = Evaluation::with(['session.event', 'questions'])->findOrFail($id);

        // Aggregated stats
        $stats = json_decode($this->stats($id)->getContent(), true);

        // Identified responses with per-question answers
        $identified = $evaluation->responses()
            ->where('is_anonymous', false)
            ->with('answers')
            ->orderByDesc('submitted_at')
            ->get()
            ->map(function ($r) use ($evaluation) {
                $byQ = $r->answers->keyBy('evaluation_question_id');
                return [
                    'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                    'email' => $r->email,
                    'phone' => $r->phone,
                    'submitted_at' => $r->submitted_at,
                    'answers' => $evaluation->questions->map(function ($q) use ($byQ) {
                        $a = $byQ->get($q->id);
                        $val = $a?->answer_value;
                        if ($q->type === 'checkbox' && $val) {
                            $decoded = json_decode($val, true);
                            $val = is_array($decoded) ? implode(', ', $decoded) : $val;
                        }
                        return [
                            'question' => $q->question_text,
                            'value' => $val,
                        ];
                    })->all(),
                ];
            });

        $pdf = Pdf::loadView('pdf.evaluation-report', [
            'evaluation' => $evaluation,
            'session' => $evaluation->session,
            'event' => $evaluation->session->event,
            'stats' => $stats,
            'identified' => $identified,
        ])->setPaper('a4');

        $filename = 'evaluation-' . $evaluation->id . '-' . now()->format('Y-m-d') . '.pdf';
        return $pdf->download($filename);
    }
}
