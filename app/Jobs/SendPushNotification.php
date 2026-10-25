<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\Device;
use App\Services\Push\FirebaseMessaging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one in-app notification to the user's registered devices.
 *
 * The row already exists by the time this runs, so a failed push costs the user
 * nothing — the message is still sitting in the inbox.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $notificationId) {}

    public function handle(FirebaseMessaging $push): void
    {
        $notification = AppNotification::query()->find($this->notificationId);

        if ($notification === null || $notification->pushed_at !== null) {
            return;
        }

        if (! $push->isConfigured()) {
            return;
        }

        $tokens = Device::query()
            ->where('user_id', $notification->user_id)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->all();

        if ($tokens === []) {
            return;
        }

        $result = $push->send(
            $tokens,
            $notification->title,
            $notification->body,
            array_merge($notification->data ?? [], [
                'type' => $notification->type->value,
                'route' => $notification->type->route(),
                'notification_id' => $notification->id,
            ]),
        );

        if ($result['invalid'] !== []) {
            // The app was uninstalled or the token rotated — drop it so future
            // sends do not keep paying for a dead device.
            Device::query()
                ->whereIn('fcm_token', $result['invalid'])
                ->update(['fcm_token' => null]);
        }

        if ($result['sent'] > 0) {
            $notification->forceFill(['pushed_at' => now()])->save();
        }
    }
}
