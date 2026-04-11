<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventSessionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventSessionTypeController extends Controller
{
    // GET /api/event-session-types
    public function index(): JsonResponse
    {
        return response()->json(
            EventSessionType::orderBy('sort_order')->orderBy('id')->get()
        );
    }

    // POST /api/event-session-types
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
        ]);

        $type = EventSessionType::create($validated);

        return response()->json($type, 201);
    }

    // PUT /api/event-session-types/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $type = EventSessionType::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
        ]);

        $type->update($validated);

        return response()->json($type->fresh());
    }

    // DELETE /api/event-session-types/{id}
    public function destroy(int $id): JsonResponse
    {
        EventSessionType::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
