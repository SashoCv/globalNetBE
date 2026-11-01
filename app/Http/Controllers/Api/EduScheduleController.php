<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduLocation;
use App\Models\EduMunicipality;
use App\Models\EduSchool;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\EduScheduleQuery;
use App\Services\Edu\GroupScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: the schedule (step 9) — a group's place and days, moving a group
 * in the calendar and the cross-program calendar itself.
 */
class EduScheduleController extends Controller
{
    public function __construct(
        private GroupScheduler $scheduler,
        private EduGroupPresenter $presenter,
        private EduScheduleQuery $query,
    ) {
    }

    // PUT /api/edu-groups/{id}/schedule
    // body: place — one of {school_id} | {location_id} | {location_text, save_location?} (all three null clears it), venue_municipality_id?;
    //       days — one of {sessions:[{date, start_time?, end_time?}]} (empty clears) | {dates:[Y-m-d]} | {start_date, days?};
    //       start_time?, end_time? (default: the program's times)
    public function update(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'school_id' => 'nullable|integer|exists:edu_schools,id',
            'location_id' => 'nullable|integer|exists:edu_locations,id',
            'location_text' => 'nullable|string|max:255',
            'save_location' => 'sometimes|boolean',
            'venue_municipality_id' => 'nullable|integer|exists:edu_municipalities,id',
            'sessions' => 'sometimes|array|max:31',
            'sessions.*.date' => 'required|date_format:Y-m-d|distinct',
            'sessions.*.start_time' => 'nullable|date_format:H:i',
            'sessions.*.end_time' => 'nullable|date_format:H:i',
            'dates' => 'sometimes|array|min:1|max:31',
            'dates.*' => 'required|date_format:Y-m-d|distinct',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'days' => 'sometimes|integer|min:1|max:31',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        $places = array_filter(['school_id', 'location_id', 'location_text'], fn ($k) => filled($data[$k] ?? null));
        if (count($places) > 1) {
            throw ValidationException::withMessages(['location_id' => 'Изберете само едно: училиште, локација или слободен текст.']);
        }
        $dateModes = array_filter(['sessions', 'dates', 'start_date'], fn ($k) => array_key_exists($k, $data));
        if (count($dateModes) > 1) {
            throw ValidationException::withMessages(['dates' => 'Внесете ги деновите на еден начин: sessions, dates или start_date со days.']);
        }
        $this->checkTimes($data['start_time'] ?? null, $data['end_time'] ?? null, 'end_time');
        foreach ($data['sessions'] ?? [] as $i => $s) {
            $this->checkTimes($s['start_time'] ?? null, $s['end_time'] ?? null, "sessions.{$i}.end_time");
        }

        $extra = [];
        DB::transaction(function () use ($group, $data, $places, &$extra) {
            // Place.
            $place = array_values($places)[0] ?? null;
            if ($place === 'school_id') {
                $location = $this->scheduler->locationForSchool(EduSchool::findOrFail($data['school_id']));
                $group->fill(['location_id' => $location->id, 'location_text' => null, 'venue_municipality_id' => $location->municipality_id]);
            } elseif ($place === 'location_id') {
                $location = EduLocation::findOrFail($data['location_id']);
                $group->fill(['location_id' => $location->id, 'location_text' => null, 'venue_municipality_id' => $location->municipality_id]);
            } elseif ($place === 'location_text') {
                $venue = $data['venue_municipality_id'] ?? $group->venue_municipality_id;
                if (!$venue) {
                    throw ValidationException::withMessages(['venue_municipality_id' => 'Изберете општина на местото на одржување.']);
                }
                $text = trim($data['location_text']);
                if (!empty($data['save_location'])) {
                    $location = EduLocation::firstOrCreate(
                        ['name' => $text, 'municipality_id' => $venue, 'kind' => 'other'],
                    );
                    $group->fill(['location_id' => $location->id, 'location_text' => null, 'venue_municipality_id' => $venue]);
                } else {
                    $group->fill(['location_id' => null, 'location_text' => $text, 'venue_municipality_id' => $venue]);
                }
            } else {
                if (array_key_exists('school_id', $data) || array_key_exists('location_id', $data) || array_key_exists('location_text', $data)) {
                    $group->fill(['location_id' => null, 'location_text' => null]);
                }
                if (array_key_exists('venue_municipality_id', $data)) {
                    $group->venue_municipality_id = $data['venue_municipality_id'];
                }
            }
            $group->save();

            // Days.
            $startTime = $data['start_time'] ?? null;
            $endTime = $data['end_time'] ?? null;
            if (array_key_exists('sessions', $data)) {
                $this->scheduler->replaceSessions($group, $data['sessions'], $startTime, $endTime);
            } elseif (array_key_exists('dates', $data)) {
                $this->scheduler->replaceSessions($group, array_map(fn ($d) => ['date' => $d], $data['dates']), $startTime, $endTime);
            } elseif (array_key_exists('start_date', $data)) {
                $days = $data['start_date'] === null ? [] : $this->scheduler->workingDays($data['start_date'], (int) ($data['days'] ?? $group->program->default_days ?: 2));
                if ($days && $days[0] !== $data['start_date']) {
                    $extra[] = ['code' => 'start_moved', 'message' => 'Почетниот датум паѓа за викенд, обуката почнува на ' . CarbonImmutable::parse($days[0])->format('d.m.Y') . '.'];
                }
                $this->scheduler->replaceSessions($group, array_map(fn ($d) => ['date' => $d], $days), $startTime, $endTime);
            } else {
                $this->scheduler->setTimes($group, $startTime, $endTime);
                $this->scheduler->syncDates($group);
            }
        });

        return $this->respond($group, $extra);
    }

    // PATCH /api/edu-groups/{id}/move  body: {day_offset: int} | {start_date: Y-m-d}
    public function move(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'day_offset' => 'required_without:start_date|nullable|integer|min:-3660|max:3660',
            'start_date' => 'required_without:day_offset|nullable|date_format:Y-m-d',
        ]);
        if (!$group->start_date) {
            return response()->json(['message' => 'Групата сè уште нема датуми за поместување.'], 422);
        }
        $offset = isset($data['day_offset'])
            ? (int) $data['day_offset']
            : (int) CarbonImmutable::parse($group->start_date->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($data['start_date']), false);
        $this->scheduler->move($group, $offset);

        return $this->respond($group, [], ['day_offset' => $offset]);
    }

    // GET /api/edu-schedule?from&to (required, Y-m-d) &program_id&trainer_id&region_id&location_id&school_id&language&status (comma list)
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'program_id' => 'nullable|integer',
            'trainer_id' => 'nullable|integer',
            'region_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'school_id' => 'nullable|integer',
            'language' => 'nullable|in:mk,sq,tr',
            'status' => 'nullable|string|max:100',
        ]);
        $from = $data['from'];
        $to = $data['to'];
        $max = (int) config('edu.schedule_max_range_days', 400);
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > $max) {
            throw ValidationException::withMessages(['to' => "Периодот може да биде најмногу {$max} дена."]);
        }

        $query = $this->query->inRange(EduGroup::query(), $from, $to);
        if (!empty($data['program_id'])) {
            $query->where('program_id', $data['program_id']);
        }
        if (!empty($data['trainer_id'])) {
            $query->where('trainer_id', $data['trainer_id']);
        }
        if (!empty($data['location_id'])) {
            $query->where('location_id', $data['location_id']);
        }
        if (!empty($data['language'])) {
            $query->where('language', $data['language']);
        }
        if (!empty($data['region_id'])) {
            $query->whereIn('venue_municipality_id', EduMunicipality::select('id')->where('region_id', $data['region_id']));
        }
        if (!empty($data['school_id'])) {
            $schoolId = $data['school_id'];
            $query->where(fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('school_id', $schoolId))
                ->orWhereHas('location', fn ($l) => $l->where('school_id', $schoolId)));
        }
        if (!empty($data['status'])) {
            $statuses = array_values(array_intersect(array_map('trim', explode(',', $data['status'])), EduGroup::STATUSES));
            $query->whereIn('status', $statuses ?: ['-']);
        }

        $groups = $this->query->items($query->orderBy('start_date')->orderBy('program_id')->orderBy('number')->get());
        $sessions = $groups->flatMap(fn ($g) => collect($g['sessions'])
            ->filter(fn ($s) => $s['date'] >= $from && $s['date'] <= $to)
            ->map(fn ($s) => [
                'id' => $s['id'],
                'group_id' => $g['id'],
                'program_id' => $g['program_id'],
                'date' => $s['date'],
                'start_time' => $s['start_time'],
                'end_time' => $s['end_time'],
                'day' => $s['day'],
                'days' => $s['days'],
            ]))
            ->sortBy(fn ($s) => [$s['date'], $s['start_time'], $s['group_id']])
            ->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'groups' => $groups,
            'sessions' => $sessions,
            'totals' => [
                'groups' => $groups->count(),
                'sessions' => $sessions->count(),
                'participants' => $groups->sum('total'),
                'by_status' => (object) $groups->countBy('status')->all(),
                'by_language' => (object) $groups->countBy('language')->all(),
            ],
        ]);
    }

    private function checkTimes(?string $start, ?string $end, string $field): void
    {
        if ($start && $end && $end <= $start) {
            throw ValidationException::withMessages([$field => 'Крајот мора да е по почетокот.']);
        }
    }

    private function respond(EduGroup $group, array $extraWarnings = [], array $more = []): JsonResponse
    {
        $group = $group->fresh();
        $payload = $this->presenter->payload($group, $group->program);
        $payload['warnings'] = array_merge($payload['warnings'], $extraWarnings, $this->scheduler->warnings($group));

        return response()->json($payload + $more);
    }
}
