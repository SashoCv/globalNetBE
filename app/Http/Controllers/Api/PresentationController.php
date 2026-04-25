<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventSession;
use App\Models\Presentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PresentationController extends Controller
{
    // GET /api/event-sessions/{id}/presentations
    public function index(int $sessionId): JsonResponse
    {
        EventSession::findOrFail($sessionId);

        $presentations = Presentation::where('event_session_id', $sessionId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($presentations);
    }

    // POST /api/event-sessions/{id}/presentations
    public function store(Request $request, int $sessionId): JsonResponse
    {
        EventSession::findOrFail($sessionId);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'hero_image' => 'nullable|string',
            'content' => 'nullable|string',
            'cta_text' => 'nullable|string|max:255',
            'cta_url' => 'nullable|string|max:1024',
            'gallery' => 'nullable|array',
            'gallery.*' => 'string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['event_session_id'] = $sessionId;

        $presentation = Presentation::create($validated);

        return response()->json($presentation, 201);
    }

    // PUT /api/presentations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $presentation = Presentation::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'hero_image' => 'nullable|string',
            'content' => 'nullable|string',
            'cta_text' => 'nullable|string|max:255',
            'cta_url' => 'nullable|string|max:1024',
            'gallery' => 'nullable|array',
            'gallery.*' => 'string',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $presentation->update($validated);

        return response()->json($presentation);
    }

    // DELETE /api/presentations/{id}
    public function destroy(int $id): JsonResponse
    {
        $presentation = Presentation::findOrFail($id);

        // Cleanup uploaded files
        if ($presentation->hero_image) {
            Storage::disk('public')->delete($presentation->hero_image);
        }
        foreach ($presentation->gallery ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $presentation->delete();
        return response()->json(null, 204);
    }

    // POST /api/presentations/{id}/upload-image
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $presentation = Presentation::findOrFail($id);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:5120',
            'kind' => 'required|in:hero,gallery',
        ]);

        $path = $request->file('image')->store('presentations', 'public');

        if ($request->input('kind') === 'hero') {
            // Delete previous hero
            if ($presentation->hero_image) {
                Storage::disk('public')->delete($presentation->hero_image);
            }
            $presentation->update(['hero_image' => $path]);
        } else {
            $gallery = $presentation->gallery ?? [];
            $gallery[] = $path;
            $presentation->update(['gallery' => $gallery]);
        }

        return response()->json([
            'path' => $path,
            'presentation' => $presentation->fresh(),
        ], 201);
    }
}
