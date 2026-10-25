<?php

namespace Database\Factories;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'google_id' => null,
            'avatar_url' => null,
            'device_id' => null,
            'role' => null,
            'points_balance' => 0,
            'total_points_earned' => 0,
            'total_points_spent' => 0,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A mobile guest account — no email, no Google link, bound to a device.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Guest '.Str::upper(Str::random(6)),
            'email' => null,
            'password' => null,
            'email_verified_at' => null,
            'device_id' => 'device-'.Str::uuid()->toString(),
        ]);
    }

    /**
     * A Google-linked mobile account.
     */
    public function google(): static
    {
        return $this->state(fn (array $attributes) => [
            'google_id' => (string) fake()->unique()->numerify('10########'),
            'avatar_url' => fake()->imageUrl(),
        ]);
    }

    /**
     * A staff account for the admin panel.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin->value,
        ]);
    }

    /**
     * A content-editor staff account.
     */
    public function editor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Editor->value,
        ]);
    }

    /**
     * A banned mobile account.
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'banned_at' => now(),
            'ban_reason' => 'Suspended by administrator.',
        ]);
    }

    /**
     * A user holding a points balance.
     */
    public function withPoints(int $balance = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'points_balance' => $balance,
            'total_points_earned' => $balance,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
