<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\RewardLimitException;
use App\Models\LuckyWheelSpin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The lucky wheel.
 *
 * The prize table, odds and daily spins are managed by the admin (see
 * RewardSettings). The client renders whatever segments it is given and then
 * animates to the index the server picked — it never decides the outcome.
 */
class LuckyWheelService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly RewardSettings $settings,
    ) {}

    public function isEnabled(): bool
    {
        $wheel = $this->settings->luckyWheel();

        return $wheel['enabled'] && $wheel['daily_spins'] > 0 && $wheel['prizes'] !== [];
    }

    /**
     * The wheel as the app should draw it.
     *
     * @return list<array{index: int, points: int}>
     */
    public function segments(): array
    {
        $segments = [];

        foreach ($this->settings->luckyWheel()['prizes'] as $index => $prize) {
            $segments[] = ['index' => $index, 'points' => $prize['points']];
        }

        return $segments;
    }

    public function dailyLimit(): int
    {
        return $this->settings->luckyWheel()['daily_spins'];
    }

    public function spinsUsedToday(User $user): int
    {
        return LuckyWheelSpin::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    public function spinsRemaining(User $user): int
    {
        return max(0, $this->dailyLimit() - $this->spinsUsedToday($user));
    }

    /**
     * Spin once and credit the prize.
     *
     * @return array{prize_points: int, segment_index: int, spins_remaining: int, wallet: array<string, mixed>}
     */
    public function spin(User $user): array
    {
        if (! $this->isEnabled()) {
            throw new RewardLimitException('The lucky wheel is closed right now.');
        }

        if ($this->spinsRemaining($user) <= 0) {
            throw new RewardLimitException(sprintf(
                'You have used all %d spins for today. Come back tomorrow.',
                $this->dailyLimit(),
            ));
        }

        $segment = $this->pick($this->settings->luckyWheel()['prizes']);

        return DB::transaction(function () use ($user, $segment): array {
            $spin = LuckyWheelSpin::query()->create([
                'user_id' => $user->id,
                'prize_points' => $segment['points'],
                'segment_index' => $segment['index'],
            ]);

            if ($segment['points'] > 0) {
                $this->wallet->credit(
                    $user,
                    LedgerType::LuckyWheel,
                    $segment['points'],
                    'Lucky wheel prize',
                    // Keyed on the spin row, so a retried request cannot pay twice.
                    'wheel:'.$spin->id,
                    'lucky_wheel_spin',
                    $spin->id,
                );
            }

            return [
                'prize_points' => $segment['points'],
                'segment_index' => $segment['index'],
                'spins_remaining' => $this->spinsRemaining($user),
                'wallet' => $this->wallet->summary($user->refresh()),
            ];
        });
    }

    /**
     * Weighted pick. Uses `random_int` so the draw is not predictable.
     *
     * @param  list<array{points: int, weight: int}>  $prizes
     * @return array{index: int, points: int}
     */
    private function pick(array $prizes): array
    {
        $roll = random_int(1, max(1, array_sum(array_column($prizes, 'weight'))));

        $cursor = 0;

        foreach ($prizes as $index => $prize) {
            $cursor += $prize['weight'];

            if ($roll <= $cursor) {
                return ['index' => $index, 'points' => $prize['points']];
            }
        }

        return ['index' => 0, 'points' => $prizes[0]['points']];
    }
}
