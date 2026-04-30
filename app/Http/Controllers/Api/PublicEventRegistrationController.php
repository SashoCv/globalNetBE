<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEventRegistrationController extends Controller
{
    // GET /api/event-registration/{qrToken}
    public function show(string $qrToken): JsonResponse
    {
        $event = Event::where('qr_token', $qrToken)
            ->with(['kotizacii' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->first();

        if (!$event) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$event->registration_open) {
            return response()->json([
                'message' => 'Регистрацијата за овој настан не е отворена.',
                'closed' => true,
            ], 410);
        }

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
                'location' => $event->location,
            ],
            'kotizacii' => $event->kotizacii->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'price' => $k->price !== null ? (float) $k->price : null,
                'currency' => $k->currency,
                'description' => $k->description,
            ]),
        ]);
    }

    // POST /api/event-registration/{qrToken}
    public function store(Request $request, string $qrToken): JsonResponse
    {
        $event = Event::where('qr_token', $qrToken)->first();

        if (!$event) {
            return response()->json(['message' => 'Invalid QR code'], 404);
        }

        if (!$event->registration_open) {
            return response()->json([
                'message' => 'Регистрацијата за овој настан не е отворена.',
                'closed' => true,
            ], 410);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:50',
            'city' => 'nullable|string|max:255',
            'event_kotizacija_id' => 'nullable|integer|exists:event_kotizacii,id',
            'hotel_stay' => 'required|boolean',
            'hotel_name' => 'nullable|string|max:255',
            'hotel_room' => 'nullable|string|max:50',
            'hotel_notes' => 'nullable|string|max:1000',
            'consent_given' => 'required|accepted',
            'consent_at' => 'required|date',
        ]);

        // If kotizacija given, ensure it belongs to this event
        if (!empty($validated['event_kotizacija_id'])) {
            $valid = $event->kotizacii()
                ->where('id', $validated['event_kotizacija_id'])
                ->where('is_active', true)
                ->exists();
            if (!$valid) {
                return response()->json([
                    'message' => 'Неважечка котизација за овој настан.',
                ], 422);
            }
        }

        // Prevent duplicate registration by email per event
        $existing = EventRegistration::where('event_id', $event->id)
            ->where('email', $validated['email'])
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Веќе сте регистрирани за овој настан со оваа е-пошта.',
                'already_registered' => true,
            ], 409);
        }

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'event_kotizacija_id' => $validated['event_kotizacija_id'] ?? null,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'city' => $validated['city'] ?? null,
            'hotel_stay' => $validated['hotel_stay'],
            'hotel_name' => $validated['hotel_stay'] ? ($validated['hotel_name'] ?? null) : null,
            'hotel_room' => $validated['hotel_stay'] ? ($validated['hotel_room'] ?? null) : null,
            'hotel_notes' => $validated['hotel_stay'] ? ($validated['hotel_notes'] ?? null) : null,
            'consent_given' => true,
            'consent_at' => $validated['consent_at'],
            'consent_ip' => $request->ip(),
            'consent_user_agent' => substr((string) $request->userAgent(), 0, 1024),
            'consent_version' => 'v1.0',
            'registered_at' => now(),
        ]);

        return response()->json([
            'message' => 'Успешно регистрирани!',
            'registration_id' => $registration->id,
            'event_name' => $event->name,
        ], 201);
    }
}
