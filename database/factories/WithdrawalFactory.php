<?php

namespace Database\Factories;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    protected $model = Withdrawal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $points = 5000;

        return [
            'user_id' => User::factory(),
            'points_amount' => $points,
            'currency' => 'USD',
            'amount_cents' => (int) round($points / 1000 * 100),
            'method' => 'paypal',
            'account_details' => ['email' => fake()->safeEmail()],
            'status' => WithdrawalStatus::Pending->value,
            'admin_note' => null,
            'processed_by' => null,
            'processed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => WithdrawalStatus::Approved->value,
            'processed_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => WithdrawalStatus::Paid->value,
            'processed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => WithdrawalStatus::Rejected->value,
            'processed_at' => now(),
        ]);
    }
}
