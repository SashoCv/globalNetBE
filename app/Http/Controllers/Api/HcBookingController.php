<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HcBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HcBookingController extends Controller
{
    /**
     * GET /api/hc-bookings
     * List bookings with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = HcBooking::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->paginate(20);

        return response()->json($bookings);
    }

    /**
     * GET /api/hc-bookings/{id}
     */
    public function show(int $id): JsonResponse
    {
        $booking = HcBooking::findOrFail($id);

        return response()->json($booking);
    }

    /**
     * PUT /api/hc-bookings/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled',
        ]);

        $booking = HcBooking::findOrFail($id);
        $booking->update(['status' => $request->status]);

        return response()->json($booking);
    }

    /**
     * DELETE /api/hc-bookings/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $booking = HcBooking::findOrFail($id);

        // Delete associated document files
        if ($booking->documents) {
            foreach ($booking->documents as $docPath) {
                Storage::disk('public')->delete($docPath);
            }
        }

        $booking->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/hc-bookings/submit (PUBLIC)
     * Submit booking from frontend with optional file uploads.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'specialty' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'hospital' => 'nullable|string|max:255',
            'preferred_date' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'documents' => 'nullable|array|max:5',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $documentPaths = [];

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $documentPaths[] = $file->store('bookings/documents', 'public');
            }
        }

        $validated['documents'] = $documentPaths;

        $booking = HcBooking::create($validated);

        return response()->json([
            'message' => 'Your booking request has been submitted successfully.',
            'data' => $booking,
        ], 201);
    }
}
