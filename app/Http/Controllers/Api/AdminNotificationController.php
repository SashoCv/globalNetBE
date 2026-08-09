<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    // GET /api/notifications
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->get('per_page', 20)));

        $notifications = AdminNotification::orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($notifications);
    }

    // GET /api/notifications/unread-count
    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'count' => AdminNotification::whereNull('read_at')->count(),
        ]);
    }

    // PATCH /api/notifications/{id}/read
    public function markRead(int $id): JsonResponse
    {
        $notification = AdminNotification::findOrFail($id);
        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($notification);
    }

    // PATCH /api/notifications/read-all
    public function markAllRead(): JsonResponse
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Сите известувања се означени како прочитани.']);
    }
}
