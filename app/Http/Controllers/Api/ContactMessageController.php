<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    /**
     * GET /api/messages
     * List all messages with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContactMessage::query()->latest();

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $messages = $query->paginate(20);

        return response()->json($messages);
    }

    /**
     * GET /api/messages/{id}
     */
    public function show(int $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);

        return response()->json($message);
    }

    /**
     * PUT /api/messages/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:new,read,replied',
        ]);

        $message = ContactMessage::findOrFail($id);
        $message->update(['status' => $request->status]);

        return response()->json($message);
    }

    /**
     * DELETE /api/messages/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $message->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/contact (PUBLIC)
     * Submit contact form from frontend.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
            'source' => 'nullable|in:globalnet,healthcare',
        ]);

        $message = ContactMessage::create($validated);

        return response()->json([
            'message' => 'Thank you for your message. We will get back to you soon.',
            'data' => $message,
        ], 201);
    }
}
