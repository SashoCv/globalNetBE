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
            'name_en' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'date' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'location_en' => 'nullable|string|max:255',
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
            'name_en' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'date' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'location_en' => 'nullable|string|max:255',
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
     * PUT /api/gallery-images/{id}/home
     * Toggle whether a single image shows in the home gallery preview.
     */
    public function toggleHome(int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);
        $image->update(['show_on_home' => ! $image->show_on_home]);

        return response()->json($image);
    }

    /**
     * PUT /api/gallery-images/{id}/hero
     * Toggle whether a single image shows in the homepage hero collage.
     */
    public function toggleHero(int $id): JsonResponse
    {
        $image = GalleryImage::findOrFail($id);

        // The hero collage has 4 slots — cap the selection at 4.
        if (! $image->show_on_hero && GalleryImage::where('show_on_hero', true)->count() >= 4) {
            return response()->json([
                'message' => 'Можете да изберете најмногу 4 слики за hero банерот. Прво тргнете една.',
            ], 422);
        }

        $image->update(['show_on_hero' => ! $image->show_on_hero]);

        return response()->json($image);
    }

    /**
     * GET /api/gallery/hero-selection (admin)
     * The images currently flagged for the hero, with their event, so the admin
     * can review the selection in one place. No fallback (unlike the public one).
     */
    public function heroSelection(): JsonResponse
    {
        $images = GalleryImage::where('show_on_hero', true)
            ->with('galleryEvent:id,name,category')
            ->orderBy('id')
            ->get();

        return response()->json($images);
    }

    /**
     * GET /api/gallery/hero-images (PUBLIC)
     * Images chosen for the homepage hero collage. Falls back to the home-preview
     * images, then to any images, so the hero is never empty.
     */
    public function heroImages(): JsonResponse
    {
        $images = GalleryImage::where('show_on_hero', true)->orderBy('id')->limit(4)->get();

        if ($images->isEmpty()) {
            $images = GalleryImage::where('show_on_home', true)->orderBy('id')->limit(4)->get();
        }
        if ($images->isEmpty()) {
            $images = GalleryImage::orderBy('id')->limit(4)->get();
        }

        return response()->json($images);
    }

    /**
     * PUT /api/gallery-images/{id}/move
     * Move an image to another event. Drops its cover flag so it doesn't become
     * an unintended cover in the destination. (Temporary admin organizing tool.)
     */
    public function moveImage(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'gallery_event_id' => 'required|integer|exists:gallery_events,id',
        ]);

        $image = GalleryImage::findOrFail($id);
        $image->update([
            'gallery_event_id' => $validated['gallery_event_id'],
            'is_cover' => false,
        ]);

        return response()->json($image);
    }

    /**
     * GET /api/gallery/home-images (PUBLIC)
     * Flat list of images chosen for the home "Од нашата галерија" preview.
     * Falls back to the first few images so the section is never empty.
     */
    public function homeImages(): JsonResponse
    {
        $images = GalleryImage::where('show_on_home', true)->orderBy('id')->get();

        if ($images->isEmpty()) {
            $images = GalleryImage::orderBy('id')->limit(6)->get();
        }

        return response()->json($images);
    }

    /**
     * GET /api/gallery/public?locale=xx (PUBLIC)
     * Get gallery events with images for frontend display, localized name/location.
     */
    public function public(Request $request): JsonResponse
    {
        $en = $request->query('locale', 'mk') === 'en';

        $query = GalleryEvent::with('images');

        if ($request->filled('show_on_home')) {
            $query->where('show_on_home', true);
        }

        $events = $query->latest()->get()->map(function (GalleryEvent $e) use ($en) {
            $data = $e->toArray();
            $data['name'] = $en && $e->name_en ? $e->name_en : $e->name;
            $data['location'] = $en && $e->location_en ? $e->location_en : $e->location;
            return $data;
        });

        return response()->json($events);
    }
}
