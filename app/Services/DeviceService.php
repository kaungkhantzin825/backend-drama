<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Repositories\DeviceRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * Registers and refreshes mobile devices.
 *
 * Called on every login (guest and Google) and whenever the app pushes a new
 * FCM token or app version.
 */
class DeviceService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Register (or refresh) the device behind a login request.
     *
     * @param  array{device_id: string, platform?: string, app_version?: string, fcm_token?: string, model?: string, os_version?: string, is_emulator?: bool, is_rooted?: bool, device_hash?: string}  $attributes
     */
    public function register(User $user, array $attributes): Device
    {
        $device = $this->devices->registerForUser($user, [
            'device_id' => $attributes['device_id'],
            'platform' => $attributes['platform'] ?? 'android',
            'app_version' => $attributes['app_version'] ?? null,
            'fcm_token' => $attributes['fcm_token'] ?? null,
            'model' => $attributes['model'] ?? null,
            'os_version' => $attributes['os_version'] ?? null,
            'is_emulator' => (bool) ($attributes['is_emulator'] ?? false),
            'is_rooted' => isset($attributes['is_rooted']) ? (bool) $attributes['is_rooted'] : null,
            'device_hash' => $attributes['device_hash'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'last_active_at' => now(),
        ]);

        // Keep the user's own device_id in sync so the guest lookup in
        // AuthService can find them again on a later cold start.
        if ($user->device_id !== $device->device_id) {
            $this->users->update($user, ['device_id' => $device->device_id]);
        }

        return $device;
    }

    /**
     * Update just the FCM token for a user's device.
     *
     * The app calls this whenever Firebase rotates the token.
     */
    public function updateFcmToken(User $user, string $deviceId, string $fcmToken): ?Device
    {
        $device = $this->devices->findByDeviceId($deviceId);

        if ($device === null) {
            return null;
        }

        return $this->devices->update($device, [
            'fcm_token' => $fcmToken,
            'user_id' => $user->id,
            'last_active_at' => now(),
        ]);
    }

    /**
     * How many accounts have been created on a single device.
     *
     * A high count is one of the withdrawal fraud signals.
     */
    public function countAccountsForDevice(string $deviceId): int
    {
        return $this->devices->countAccountsForDevice($deviceId);
    }

    /**
     * Flag devices that reported emulator fingerprints.
     *
     * @return array<int, string>
     */
    public function emulatorDeviceIds(): array
    {
        return Device::query()
            ->where('is_emulator', true)
            ->pluck('device_id')
            ->all();
    }

    /**
     * Atomically bind a device to a user inside a transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerAtomically(User $user, array $attributes): Device
    {
        return DB::transaction(fn (): Device => $this->register($user, $attributes));
    }
}
