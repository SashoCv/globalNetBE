<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationAnswer;
use App\Models\EvaluationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEvaluationController extends Controller
{
    // GET /api/evaluation/{qrToken}
    public function show(string $qrToken): JsonResponse
    {
        $evaluation = Evaluation::where('qr_token', $qrToken)
            ->with(['session.event:id,name', 'questions'])
            ->first();

        if (!$evaluation) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$evaluation->is_active) {
            return response()->json([
                'message' => 'Оваа евалуација не е активна.',
                'ended' => true,
            ], 410);
        }

        return response()->json([
            'evaluation' => [
                'id' => $evaluation->id,
                'title' => $evaluation->title,
                'description' => $evaluation->description,
                'anonymity_mode' => $evaluation->anonymity_mode,
            ],
            'session' => [
                'name' => $evaluation->session->name,
                'description' => $evaluation->session->description,
                'logo' => $evaluation->session->logo,
            ],
            'event' => [
                'name' => $evaluation->session->event->name,
            ],
            'questions' => $evaluation->questions->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'type' => $q->type,
                'options' => $q->options,
                'required' => $q->required,
            ]),
        ]);
    }

    // POST /api/evaluation/{qrToken}
    public function submit(Request $request, string $qrToken): JsonResponse
    {
        $evaluation = Evaluation::where('qr_token', $qrToken)
            ->with('questions')
            ->first();

        if (!$evaluation) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$evaluation->is_active) {
            return response()->json([
                'message' => 'Оваа евалуација не е активна.',
                'ended' => true,
            ], 410);
        }

        $validated = $request->validate([
            'is_anonymous' => 'required|boolean',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'consent_given' => 'nullable|boolean',
            'consent_at' => 'nullable|date',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.value' => 'nullable',
        ]);

        // Enforce anonymity_mode policy
        $mode = $evaluation->anonymity_mode ?? 'both';
        if ($mode === 'anonymous' && !$validated['is_anonymous']) {
            return response()->json(['message' => 'Оваа евалуација е само анонимна.'], 422);
        }
        if ($mode === 'identified' && $validated['is_anonymous']) {
            return response()->json(['message' => 'Оваа евалуација бара име и е-пошта.'], 422);
        }

        // If not anonymous, require name + email + consent
        if (!$validated['is_anonymous']) {
            $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'consent_given' => 'required|accepted',
                'consent_at' => 'required|date',
            ]);
        }

        // Validate required questions answered
        $questionsById = $evaluation->questions->keyBy('id');
        $answersByQuestion = collect($validated['answers'])->keyBy('question_id');

        foreach ($evaluation->questions as $q) {
            if (!$q->required) continue;
            $a = $answersByQuestion->get($q->id);
            $value = $a['value'] ?? null;
            $isEmpty = $value === null || $value === '' || (is_array($value) && count($value) === 0);
            if ($isEmpty) {
                return response()->json([
                    'message' => 'Прашањето "' . $q->question_text . '" е задолжително.',
                    'question_id' => $q->id,
                ], 422);
            }
        }

        // Create response
        $response = EvaluationResponse::create([
            'evaluation_id' => $evaluation->id,
            'first_name' => $validated['is_anonymous'] ? null : ($validated['first_name'] ?? null),
            'last_name' => $validated['is_anonymous'] ? null : ($validated['last_name'] ?? null),
            'email' => $validated['is_anonymous'] ? null : ($validated['email'] ?? null),
            'phone' => $validated['is_anonymous'] ? null : ($validated['phone'] ?? null),
            'is_anonymous' => $validated['is_anonymous'],
            'submitted_at' => now(),
            'consent_given' => !$validated['is_anonymous'],
            'consent_at' => $validated['is_anonymous'] ? null : ($validated['consent_at'] ?? null),
            'consent_ip' => $validated['is_anonymous'] ? null : $request->ip(),
            'consent_user_agent' => $validated['is_anonymous'] ? null : substr((string) $request->userAgent(), 0, 1024),
            'consent_version' => $validated['is_anonymous'] ? null : 'v1.0',
        ]);

        // Create answers
        foreach ($validated['answers'] as $answer) {
            $qid = (int) $answer['question_id'];
            if (!$questionsById->has($qid)) continue;

            $value = $answer['value'] ?? null;

            // Encode arrays (checkbox) as JSON
            if (is_array($value)) {
                $value = json_encode(array_values($value));
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            EvaluationAnswer::create([
                'evaluation_response_id' => $response->id,
                'evaluation_question_id' => $qid,
                'answer_value' => $value,
            ]);
        }

        return response()->json([
            'message' => 'Благодариме за вашата евалуација!',
            'response_id' => $response->id,
        ], 201);
    }
}
