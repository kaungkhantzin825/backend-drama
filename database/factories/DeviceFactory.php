<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => 'device-'.Str::uuid()->toString(),
            'user_id' => User::factory(),
            'fcm_token' => Str::random(140),
            'platform' => Platform::Android->value,
            'app_version' => '1.0.0',
            'model' => fake()->randomElement(['Pixel 7', 'Galaxy S23', 'iPhone 15']),
            'os_version' => fake()->randomElement(['Android 14', 'iOS 17.4']),
            'is_emulator' => false,
            'is_rooted' => false,
            'device_hash' => hash('sha256', Str::random(32)),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'DramaLoop/1.0.0 (Android 14)',
            'last_active_at' => now(),
        ];
    }

    /**
     * A device that reported an emulator fingerprint.
     */
    public function emulator(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_emulator' => true,
            'model' => 'sdk_gphone64_x86_64',
        ]);
    }

    /**
     * A device bound to an existing user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
