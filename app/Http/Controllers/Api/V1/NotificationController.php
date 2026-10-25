<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's in-app inbox.
 *
 * Read-only apart from the read markers: notifications are written by the
 * server in response to events (payout approved, reward granted), never by the
 * client.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->notifications
            ->forUser($request->user())
            ->through(fn (AppNotification $notification): array => $this->present($notification));

        return ApiResponse::paginated('Notifications loaded.', $paginator);
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Kept separate from the list so the tab badge can refresh without pulling
     * a whole page of notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success('Unread count loaded.', [
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * POST /api/v1/notifications/{notification}/read
     */
    public function read(Request $request, int $notification): JsonResponse
    {
        $updated = $this->notifications->markRead($request->user(), $notification);

        if ($updated === null) {
            // Same answer for "does not exist" and "belongs to someone else",
            // so the endpoint cannot be used to probe other users' inboxes.
            return ApiResponse::error('Notification not found.', null, 404);
        }

        return ApiResponse::success('Notification marked as read.', $this->present($updated));
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function readAll(Request $request): JsonResponse
    {
        $updated = $this->notifications->markAllRead($request->user());

        return ApiResponse::success('All notifications marked as read.', [
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AppNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'type_label' => $notification->type->label(),
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data,
            'route' => $notification->type->route(),
            'is_read' => $notification->isRead(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
