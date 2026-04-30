<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventKotizacija;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventKotizacijaController extends Controller
{
    // GET /api/events/{id}/kotizacii
    public function index(int $eventId): JsonResponse
    {
        Event::findOrFail($eventId);
        $items = EventKotizacija::where('event_id', $eventId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        return response()->json($items);
    }

    // POST /api/events/{id}/kotizacii
    public function store(Request $request, int $eventId): JsonResponse
    {
        Event::findOrFail($eventId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
        $validated['event_id'] = $eventId;
        $validated['currency'] = $validated['currency'] ?? 'MKD';

        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) EventKotizacija::where('event_id', $eventId)->max('sort_order') + 1;
        }

        $k = EventKotizacija::create($validated);
        return response()->json($k, 201);
    }

    // PUT /api/event-kotizacii/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $k = EventKotizacija::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $k->update($validated);
        return response()->json($k);
    }

    // DELETE /api/event-kotizacii/{id}
    public function destroy(int $id): JsonResponse
    {
        $k = EventKotizacija::findOrFail($id);
        $hasRegistrations = $k->registrations()->exists();
        if ($hasRegistrations) {
            // Soft-disable instead of deleting if there are registrations referencing it
            $k->update(['is_active' => false]);
            return response()->json(['message' => 'Котизацијата има регистрации — деактивирана е, не избришана.'], 200);
        }
        $k->delete();
        return response()->json(null, 204);
    }
}
