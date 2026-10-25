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
 * The prize table lives in `config/rewards.php`. The client renders whatever
 * segments it is given and then animates to the index the server picked — it
 * never decides the outcome itself.
 */
class LuckyWheelService
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    /**
     * The wheel as the app should draw it.
     *
     * @return list<array{index: int, points: int}>
     */
    public function segments(): array
    {
        $prizes = (array) config('rewards.lucky_wheel.prizes', []);

        $segments = [];

        foreach (array_values($prizes) as $index => $prize) {
            $segments[] = [
                'index' => $index,
                'points' => (int) ($prize['points'] ?? 0),
            ];
        }

        return $segments;
    }

    public function dailyLimit(): int
    {
        return max(1, (int) config('rewards.lucky_wheel.daily_spins', 3));
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
        if ($this->spinsRemaining($user) <= 0) {
            throw new RewardLimitException(sprintf(
                'You have used all %d spins for today. Come back tomorrow.',
                $this->dailyLimit(),
            ));
        }

        $segments = $this->segments();

        if ($segments === []) {
            throw new RewardLimitException('The wheel is not configured.');
        }

        $segment = $this->pick($segments);

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
     * @param  list<array{index: int, points: int}>  $segments
     * @return array{index: int, points: int}
     */
    private function pick(array $segments): array
    {
        $prizes = array_values((array) config('rewards.lucky_wheel.prizes', []));

        $weights = array_map(
            fn (array $prize): int => max(1, (int) ($prize['weight'] ?? 1)),
            $prizes,
        );

        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));

        $cursor = 0;

        foreach ($segments as $position => $segment) {
            $cursor += $weights[$position] ?? 1;

            if ($roll <= $cursor) {
                return $segment;
            }
        }

        return $segments[0];
    }
}
