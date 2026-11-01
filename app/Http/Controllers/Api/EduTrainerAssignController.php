<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduProgram;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\GroupScheduler;
use App\Services\Edu\TrainerMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Обуки: trainers of the groups (step 8) — candidates, manual assignment
 * and automatic assignment for a whole program.
 */
class EduTrainerAssignController extends Controller
{
    public function __construct(
        private TrainerMatcher $matcher,
        private GroupScheduler $scheduler,
        private EduGroupPresenter $presenter,
    ) {
    }

    // GET /api/edu-groups/{id}/trainer-candidates?include_ineligible=1
    public function candidates(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);

        return response()->json($this->matcher->candidates($group, [
            'include_ineligible' => $request->boolean('include_ineligible'),
        ]));
    }

    // PUT /api/edu-groups/{id}/trainer  body: {trainer_id: int|null, force?: bool}
    public function assign(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'trainer_id' => 'present|nullable|integer|exists:edu_trainers,id',
            'force' => 'sometimes|boolean',
        ]);

        $check = null;
        if (empty($data['trainer_id'])) {
            $group->update(['trainer_id' => null, 'trainer_override' => false]);
        } else {
            $check = $this->matcher->check($group, (int) $data['trainer_id']);
            if ($check['blocking'] && !$request->boolean('force')) {
                return response()->json([
                    'message' => 'Обучувачот не ги исполнува правилата: ' . implode(' ', TrainerMatcher::messages($check['blocking'])) . ' Потврдете со „force“ за да го доделите сепак.',
                    'problems' => $check['problems'],
                    'blocking' => $check['blocking'],
                    'conflicts' => $check['conflicts'],
                ], 422);
            }
            $group->update(['trainer_id' => $check['trainer_id'], 'trainer_override' => $check['blocking'] !== []]);
        }
        $this->scheduler->refreshStatus($group);
        $group = $group->fresh();

        return response()->json($this->presenter->payload($group, $group->program) + [
            'trainer_check' => $check ? [
                'eligible' => $check['eligible'],
                'score' => $check['score'],
                'reasons' => $check['reasons'],
                'problems' => $check['problems'],
                'blocking' => $check['blocking'],
                'conflicts' => $check['conflicts'],
            ] : null,
        ]);
    }

    // POST /api/edu-programs/{id}/trainers/auto  body: {overwrite?: bool, pick?: unique|best, dry_run?: bool}
    public function auto(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $request->validate([
            'overwrite' => 'sometimes|boolean',
            'pick' => 'sometimes|in:unique,best',
            'dry_run' => 'sometimes|boolean',
        ]);
        if ($program->trainer_assignment_mode === 'manual') {
            return response()->json(['message' => 'Програмата е во рачен режим на доделување обучувачи. Изберете обучувач за секоја група.'], 409);
        }
        $overwrite = $request->boolean('overwrite');
        $pickBest = $request->input('pick', 'unique') === 'best';
        $dryRun = $request->boolean('dry_run');

        $all = EduGroup::where('program_id', $program->id)->whereNotIn('status', ['cancelled', 'held'])->with('sessions')->get();
        // Manual choices (trainer_override) are always kept.
        $groups = $all->filter(fn (EduGroup $g) => !$g->trainer_override && ($overwrite || !$g->trainer_id))
            ->sortBy(fn (EduGroup $g) => [$g->start_date === null ? 1 : 0, $g->start_date?->format('Y-m-d') ?? '', $g->number])
            ->values();
        $ignore = $groups->pluck('id')->all();
        $trainers = $this->matcher->trainers();

        $bookings = [];
        $load = [];
        $assigned = [];
        $needsChoice = [];
        $noCandidate = [];
        $undated = 0;

        foreach ($groups as $group) {
            $cands = $this->matcher->candidates($group, [
                'include_ineligible' => true,
                'trainers' => $trainers,
                'ignore_group_ids' => $ignore,
                'bookings' => $bookings,
                'load' => $load,
            ]);
            $eligible = array_values(array_filter($cands, fn ($c) => $c['eligible']));
            if ($group->sessions->isEmpty()) {
                $undated++;
            }
            $base = ['group_id' => $group->id, 'number' => $group->number, 'language' => $group->language, 'start_date' => $group->start_date?->format('Y-m-d'), 'previous_trainer_id' => $group->trainer_id];

            if (!$eligible) {
                $noCandidate[] = $base + ['candidates' => array_map(fn ($c) => $this->brief($c), array_slice($cands, 0, 3))];
                continue;
            }
            if (count($eligible) > 1 && !$pickBest) {
                $needsChoice[] = $base + ['candidates' => array_map(fn ($c) => $this->brief($c), $eligible)];
                continue;
            }

            $pick = $eligible[0];
            $tid = $pick['trainer_id'];
            $load[$tid] = ($load[$tid] ?? 0) + 1;
            foreach ($group->sessions as $s) {
                $bookings[$tid][] = [
                    'group_id' => $group->id, 'program_id' => $program->id, 'program_title' => $program->title, 'number' => $group->number,
                    'date' => $s->date->format('Y-m-d'), 'start_time' => $s->start_time, 'end_time' => $s->end_time,
                ];
            }
            $assigned[] = $base + [
                'trainer_id' => $tid,
                'full_name' => $pick['trainer']['full_name'],
                'score' => $pick['score'],
                'reasons' => $pick['reasons'],
                'problems' => $pick['problems'],
                'alternatives' => count($eligible) - 1,
            ];
        }

        if (!$dryRun) {
            DB::transaction(function () use ($assigned, $needsChoice, $noCandidate, $overwrite) {
                foreach ($assigned as $row) {
                    $group = EduGroup::find($row['group_id']);
                    $group->update(['trainer_id' => $row['trainer_id'], 'trainer_override' => false]);
                    $this->scheduler->refreshStatus($group);
                }
                // With overwrite the previous trainer of a group left without
                // a pick is removed, so no stale (now conflicting) choice stays.
                if ($overwrite) {
                    foreach (array_merge($needsChoice, $noCandidate) as $row) {
                        if ($row['previous_trainer_id']) {
                            $group = EduGroup::find($row['group_id']);
                            $group->update(['trainer_id' => null]);
                            $this->scheduler->refreshStatus($group);
                        }
                    }
                }
            });
        }

        return response()->json([
            'dry_run' => $dryRun,
            'pick' => $pickBest ? 'best' : 'unique',
            'assigned' => $assigned,
            'needs_choice' => $needsChoice,
            'no_candidate' => $noCandidate,
            'skipped' => $all->count() - $groups->count(),
            'summary' => [
                'groups' => $groups->count(),
                'assigned' => count($assigned),
                'needs_choice' => count($needsChoice),
                'no_candidate' => count($noCandidate),
                'undated' => $undated,
            ],
            'note' => $undated > 0
                ? "{$undated} групи немаат датуми: за нив обучувачите се избрани само според матрицата, јазикот и оптоварувањето. Конфликтите во терминот ќе се проверат при закажувањето."
                : null,
        ]);
    }

    private function brief(array $c): array
    {
        return [
            'trainer_id' => $c['trainer_id'],
            'full_name' => $c['trainer']['full_name'],
            'languages' => $c['trainer']['languages'],
            'eligible' => $c['eligible'],
            'score' => $c['score'],
            'reasons' => $c['reasons'],
            'problems' => $c['problems'],
            'blocking' => $c['blocking'],
            'load' => $c['load'],
        ];
    }
}
