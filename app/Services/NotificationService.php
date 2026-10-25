<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The user's inbox.
 *
 * Write order matters: persist first, push second. The row is the durable
 * record; the push is a queued side effect that may fail, retry, or be skipped
 * entirely when FCM is not configured.
 */
class NotificationService
{
    /**
     * Record a notification and queue its push delivery.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function send(
        User $user,
        NotificationType $type,
        string $title,
        ?string $body = null,
        array $data = [],
    ): AppNotification {
        $notification = AppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        SendPushNotification::dispatch($notification->id);

        return $notification;
    }

    /**
     * A page of the user's inbox, newest first.
     */
    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return AppNotification::query()
            ->forUser($user)
            ->latest('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return AppNotification::query()->forUser($user)->unread()->count();
    }

    /**
     * Mark one notification read, scoped to its owner.
     *
     * Returns null when the id does not belong to this user, so the caller can
     * answer 404 without leaking the existence of someone else's row.
     */
    public function markRead(User $user, int $notificationId): ?AppNotification
    {
        $notification = AppNotification::query()
            ->forUser($user)
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            return null;
        }

        $notification->markRead();

        return $notification;
    }

    /**
     * Mark every unread notification read. Returns how many changed.
     */
    public function markAllRead(User $user): int
    {
        return AppNotification::query()
            ->forUser($user)
            ->unread()
            ->update(['read_at' => now()]);
    }
}
