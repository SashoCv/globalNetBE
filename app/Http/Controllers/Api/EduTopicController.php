<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: training topics and each topic's list of trainers.
 */
class EduTopicController extends Controller
{
    // GET /api/edu-topics
    public function index(): JsonResponse
    {
        return response()->json(EduTopic::query()->withCount('trainers')->orderBy('id')->get());
    }

    // GET /api/edu-topics/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->load(EduTopic::findOrFail($id)));
    }

    // POST /api/edu-topics
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:2000',
            'short_title' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:10000',
        ]);

        return response()->json(EduTopic::create($data)->loadCount('trainers'), 201);
    }

    // PUT /api/edu-topics/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $topic = EduTopic::findOrFail($id);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:2000',
            'short_title' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:10000',
        ]);
        $topic->update($data);

        return response()->json($topic->fresh()->loadCount('trainers'));
    }

    // DELETE /api/edu-topics/{id}
    public function destroy(int $id): JsonResponse
    {
        EduTopic::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // PUT /api/edu-topics/{id}/trainers  body: { trainer_ids: [] }
    public function syncTrainers(Request $request, int $id): JsonResponse
    {
        $topic = EduTopic::findOrFail($id);
        $data = $request->validate([
            'trainer_ids' => 'present|array',
            'trainer_ids.*' => 'integer|distinct',
        ]);

        $ids = array_map('intval', $data['trainer_ids']);
        if ($missing = array_diff($ids, EduTrainer::whereIn('id', $ids)->pluck('id')->all())) {
            throw ValidationException::withMessages([
                'trainer_ids' => 'Непостоечки обучувачи: ' . implode(', ', $missing) . '.',
            ]);
        }
        $topic->trainers()->sync($ids);

        return response()->json($this->load($topic));
    }

    private function load(EduTopic $topic): EduTopic
    {
        return $topic->load(['trainers' => fn ($q) => $q->orderBy('full_name')
            ->select('edu_trainers.id', 'full_name', 'full_name_sq', 'languages', 'home_municipality_id', 'is_active')])
            ->loadCount('trainers');
    }
}
