<?php

namespace App\Repositories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data-access for Device records.
 */
class DeviceRepository
{
    /**
     * Find a device by its app-supplied identifier.
     */
    public function findByDeviceId(string $deviceId): ?Device
    {
        return Device::query()
            ->where('device_id', $deviceId)
            ->first();
    }

    /**
     * Create a device row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Device
    {
        return Device::query()->create($attributes);
    }

    /**
     * Update an existing device row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Device $device, array $attributes): Device
    {
        $device->fill($attributes)->save();

        return $device->refresh();
    }

    /**
     * Register (or refresh) a device for a user.
     *
     * The `device_id` is stable per app install, so this is an upsert keyed on
     * it. When the device already belonged to a different account we rebind
     * it — the fraud detection in Phase 4 is what actually acts on that
     * signal, and we don't want to lock legitimate users out of the app.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerForUser(User $user, array $attributes): Device
    {
        $device = $this->findByDeviceId($attributes['device_id']);

        if ($device === null) {
            return $this->create($attributes + ['user_id' => $user->id]);
        }

        return $this->update($device, $attributes + ['user_id' => $user->id]);
    }

    /**
     * How many distinct accounts are bound to a device.
     *
     * Used by the withdrawal fraud checks ("many accounts per device").
     */
    public function countAccountsForDevice(string $deviceId): int
    {
        return (int) User::query()
            ->where('device_id', $deviceId)
            ->whereNull('role')
            ->count();
    }

    /**
     * Devices belonging to a user.
     *
     * @return Builder<Device>
     */
    public function forUser(User $user): Builder
    {
        return Device::query()->where('user_id', $user->id);
    }
}
