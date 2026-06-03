<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

#[Group('Admin Notifications', weight: 57)]
class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        /** @var User $user */
        $user = $request->user();

        $notifications = $user->notifications()->paginate($this->perPage($request, default: 20));

        return response()->json([
            'notifications' => NotificationResource::collection($notifications->items()),
            'unread_count' => $user->unreadNotifications()->count(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markAsRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->ensureAdminAccess($request);

        /** @var User $user */
        $user = $request->user();

        abort_if($notification->notifiable_id !== $user->id, 403);

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => NotificationResource::make($notification),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
