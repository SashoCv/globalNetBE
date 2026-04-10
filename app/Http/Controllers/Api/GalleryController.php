<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GalleryEvent;
use App\Models\GalleryImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    /**
     * GET /api/gallery-events
     * List all gallery events with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GalleryEvent::with('images')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('show_on_home')) {
            $query->where('show_on_home', filter_var($request->show_on_home, FILTER_VALIDATE_BOOLEAN));
        }

        $events = $query->paginate(20);

        return response()->json($events);
    }

    /**
     * POST /api/gallery-events
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'date' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'featured' => 'nullable|boolean',
            'show_on_home' => 'nullable|boolean',
        ]);

        $event = GalleryEvent::create($validated);

        return response()->json($event->load('images'), 201);
    }

    /**
     * PUT /api/gallery-events/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = GalleryEvent::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'date' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'featured' => 'nullable|boolean',
            'show_on_home' => 'nullable|boolean',
        ]);

        $event->update($validated);

        return response()->json($event->load('images'));
    }

    /**
     * DELETE /api/gallery-events/{id}
     * Delete event and cascade-delete all images (files + records).
     */
    public function destroy(int $id): JsonResponse
    {
        $event = GalleryEvent::with('images')->findOrFail($id);

        // Delete image files from storage
        foreach ($event->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        $event->delete(); // cascade deletes image records via FK

        return response()->json(null, 204);
    }

    /**
     * POST /api/gallery-events/{id}/images
     * Upload images to an event.
     */
    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $event = GalleryEvent::findOrFail($id);

        $request->validate([
            'images' => 'required|array|min:1',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ]);

        $uploaded = [];

        foreach ($request->file('images') as $file) {
            $path = $file->store('gallery', 'public');

            $uploaded[] = $event->images()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'is_cover' => false,
            ]);
        }

        return response()->json($uploaded, 201);
    }

    /**
     * DELETE /api/gallery-images/{id}
     * Delete a single image.
     */
    public function destroyImage(int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json(null, 204);
    }

    /**
     * PUT /api/gallery-images/{id}/cover
     * Set an image as the cover for its event.
     */
    public function setCover(int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);

        // Unset all other covers for this event
        GalleryImage::where('gallery_event_id', $image->gallery_event_id)
            ->update(['is_cover' => false]);

        $image->update(['is_cover' => true]);

        return response()->json($image);
    }

    /**
     * GET /api/gallery/public (PUBLIC)
     * Get gallery events with images for frontend display.
     */
    public function public(Request $request): JsonResponse
    {
        $query = GalleryEvent::with('images');

        if ($request->filled('show_on_home')) {
            $query->where('show_on_home', true);
        }

        $events = $query->latest()->get();

        return response()->json($events);
    }
}
