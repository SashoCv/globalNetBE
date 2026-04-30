<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\EventAttendee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    // GET /api/events - list events with session count, paginated
    public function index(Request $request): JsonResponse
    {
        $events = Event::withCount('sessions')
            ->orderBy('start_date', 'asc')
            ->paginate(20);
        return response()->json($events);
    }

    // POST /api/events - create event
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,active,completed',
            'total_participants' => 'nullable|integer|min:0',
        ]);
        $event = Event::create($validated);
        return response()->json($event->load('sessions'), 201);
    }

    // PUT /api/events/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,active,completed',
            'total_participants' => 'nullable|integer|min:0',
        ]);
        $event->update($validated);
        return response()->json($event->fresh()->load('sessions'));
    }

    // DELETE /api/events/{id}
    public function destroy(int $id): JsonResponse
    {
        Event::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // GET /api/events/{id}/sessions
    public function sessions(int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $sessions = $event->sessions()
            ->with('type')
            ->withCount('attendees')
            ->orderBy('sort_order')
            ->get();
        return response()->json($sessions);
    }

    // POST /api/events/{id}/sessions
    public function storeSession(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:1024',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date',
            'sort_order' => 'nullable|integer',
            'event_session_type_id' => 'nullable|integer|exists:event_session_types,id',
        ]);
        $validated['event_id'] = $event->id;
        // qr_token auto-generated in model boot
        $session = EventSession::create($validated);
        return response()->json($session->fresh()->load('type'), 201);
    }

    // PUT /api/event-sessions/{id}
    public function updateSession(Request $request, int $id): JsonResponse
    {
        $session = EventSession::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:1024',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'event_session_type_id' => 'nullable|integer|exists:event_session_types,id',
        ]);
        $session->update($validated);
        return response()->json($session->fresh()->load('type'));
    }

    // DELETE /api/event-sessions/{id}
    public function destroySession(int $id): JsonResponse
    {
        EventSession::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // GET /api/events/{id}/stats?category={typeId}
    public function stats(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $allSessions = $event->sessions()->with('type')->orderBy('sort_order')->get();

        // Optional category filter
        $categoryId = $request->query('category');
        $sessions = $categoryId
            ? $allSessions->filter(fn($s) => (string) $s->event_session_type_id === (string) $categoryId)
            : $allSessions;

        $totalSessions = $sessions->count();

        // Get all unique attendee IDs across filtered sessions
        $sessionIds = $sessions->pluck('id');

        // Attendance records grouped by attendee
        $attendanceByAttendee = \DB::table('event_attendance')
            ->whereIn('event_session_id', $sessionIds)
            ->select('event_attendee_id', \DB::raw('COUNT(*) as sessions_attended'))
            ->groupBy('event_attendee_id')
            ->get();

        $attendeeIds = $attendanceByAttendee->pluck('event_attendee_id');
        $attendees = EventAttendee::whereIn('id', $attendeeIds)->get()->keyBy('id');

        // Grab latest phone per attendee from attendance records
        $phonesByAttendee = \DB::table('event_attendance')
            ->whereIn('event_session_id', $sessionIds)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderByDesc('checked_in_at')
            ->get()
            ->unique('event_attendee_id')
            ->pluck('phone', 'event_attendee_id');

        $minPercent = $request->query('min_percent', 0);

        $attendeeStats = $attendanceByAttendee->map(function ($row) use ($attendees, $totalSessions, $phonesByAttendee) {
            $attendee = $attendees->get($row->event_attendee_id);
            if (!$attendee) return null;
            $percent = $totalSessions > 0 ? round(($row->sessions_attended / $totalSessions) * 100, 1) : 0;
            return [
                'id' => $attendee->id,
                'first_name' => $attendee->first_name,
                'last_name' => $attendee->last_name,
                'email' => $attendee->email,
                'phone' => $phonesByAttendee->get($row->event_attendee_id),
                'city' => $attendee->city,
                'license_number' => $attendee->license_number,
                'sessions_attended' => $row->sessions_attended,
                'total_sessions' => $totalSessions,
                'percent' => $percent,
            ];
        })->filter()->filter(fn($a) => $a['percent'] >= $minPercent)->values();

        // Per-session counts (always return ALL sessions with type for grouping)
        $sessionStats = $allSessions->map(function ($session) {
            return [
                'id' => $session->id,
                'name' => $session->name,
                'description' => $session->description,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'event_session_type_id' => $session->event_session_type_id,
                'type' => $session->type ? [
                    'id' => $session->type->id,
                    'name' => $session->type->name,
                    'color' => $session->type->color,
                ] : null,
                'attendee_count' => \DB::table('event_attendance')
                    ->where('event_session_id', $session->id)->count(),
            ];
        });

        return response()->json([
            'event' => $event,
            'total_sessions' => $totalSessions,
            'total_participants' => $event->total_participants,
            'registered_attendees' => $attendeeIds->count(),
            'sessions' => $sessionStats,
            'attendees' => $attendeeStats,
        ]);
    }

    // GET /api/event-sessions/{id}/attendees
    public function sessionAttendees(int $id): JsonResponse
    {
        $session = EventSession::findOrFail($id);
        $attendees = $session->attendees()->get();
        return response()->json($attendees);
    }
}
