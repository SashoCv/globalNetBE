<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventSession;
use App\Models\EventAttendee;
use App\Models\EventAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // GET /api/attendance/{qrToken} - get session info for QR form
    public function show(string $qrToken): JsonResponse
    {
        $session = EventSession::where('qr_token', $qrToken)
            ->with('event:id,name,start_date,end_date,location')
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        return response()->json([
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'description' => $session->description,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'is_active' => $session->is_active,
            ],
            'event' => [
                'name' => $session->event->name,
                'start_date' => $session->event->start_date,
                'end_date' => $session->event->end_date,
                'location' => $session->event->location,
            ],
        ]);
    }

    // POST /api/attendance/{qrToken} - register attendance
    public function store(Request $request, string $qrToken): JsonResponse
    {
        $session = EventSession::where('qr_token', $qrToken)->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$session->is_active) {
            return response()->json([
                'message' => 'Регистрацијата за оваа сесија не е активна.',
                'session_ended' => true,
            ], 410);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'city' => 'nullable|string|max:255',
            'license_number' => 'nullable|string|max:255',
        ]);

        // Find or create attendee by email
        $attendee = EventAttendee::firstOrCreate(
            ['email' => $validated['email']],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'city' => $validated['city'] ?? null,
                'license_number' => $validated['license_number'] ?? null,
            ]
        );

        // Update name/city if existing attendee (they might have changed)
        if (!$attendee->wasRecentlyCreated) {
            $attendee->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'city' => $validated['city'] ?? $attendee->city,
                'license_number' => $validated['license_number'] ?? $attendee->license_number,
            ]);
        }

        // Check if already checked in
        $existing = EventAttendance::where('event_session_id', $session->id)
            ->where('event_attendee_id', $attendee->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Веќе сте регистрирани за оваа сесија.',
                'already_registered' => true,
            ], 409);
        }

        EventAttendance::create([
            'event_session_id' => $session->id,
            'event_attendee_id' => $attendee->id,
        ]);

        return response()->json([
            'message' => 'Успешно регистрирани!',
            'already_registered' => false,
        ], 201);
    }
}
