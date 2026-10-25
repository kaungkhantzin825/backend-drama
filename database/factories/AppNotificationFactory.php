<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppNotification>
 */
class AppNotificationFactory extends Factory
{
    protected $model = AppNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => NotificationType::System->value,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'data' => [],
            'read_at' => null,
            'pushed_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function pushed(): static
    {
        return $this->state(fn (): array => ['pushed_at' => now()]);
    }
}
