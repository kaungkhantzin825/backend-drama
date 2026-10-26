<?php

namespace App\Services;

use App\Enums\AdEventStatus;
use App\Enums\LedgerType;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\RewardLimitException;
use App\Models\AdEvent;
use App\Models\PointsLedger;
use App\Models\User;
use App\Models\WatchRewardState;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for a user's balance.
 *
 * Nothing in the app writes `users.points_balance` directly: every change goes
 * through `append()`, which writes an immutable ledger row and updates the
 * cached balance in the same transaction under a row lock. That is what makes
 * the balance auditable and concurrent requests safe.
 */
class WalletService
{
    public function __construct(private readonly RewardSettings $settings) {}

    /**
     * Credit points (positive delta).
     *
     * @param  array<string, mixed>  $meta
     */
    public function credit(
        User $user,
        LedgerType $type,
        int $points,
        string $description,
        ?string $idempotencyKey = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
    ): PointsLedger {
        return $this->append($user, $type, abs($points), $description, $idempotencyKey, $referenceType, $referenceId, $meta);
    }

    /**
     * Debit points (negative delta). Throws when the balance would go negative.
     *
     * @param  array<string, mixed>  $meta
     */
    public function debit(
        User $user,
        LedgerType $type,
        int $points,
        string $description,
        ?string $idempotencyKey = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
    ): PointsLedger {
        return $this->append($user, $type, -abs($points), $description, $idempotencyKey, $referenceType, $referenceId, $meta);
    }

    /**
     * Append one ledger row and move the cached balance in the same transaction.
     *
     * @param  array<string, mixed>  $meta
     */
    private function append(
        User $user,
        LedgerType $type,
        int $delta,
        string $description,
        ?string $idempotencyKey,
        ?string $referenceType,
        ?int $referenceId,
        array $meta,
    ): PointsLedger {
        return DB::transaction(function () use ($user, $type, $delta, $description, $idempotencyKey, $referenceType, $referenceId, $meta): PointsLedger {
            // A retried request must not pay twice.
            if ($idempotencyKey !== null) {
                $existing = PointsLedger::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // Lock the user row so two concurrent requests cannot both read the
            // same balance and overspend.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $balanceAfter = $locked->points_balance + $delta;

            if ($balanceAfter < 0) {
                throw InsufficientPointsException::for(abs($delta), $locked->points_balance);
            }

            $locked->forceFill([
                'points_balance' => $balanceAfter,
                'total_points_earned' => $delta > 0
                    ? $locked->total_points_earned + $delta
                    : $locked->total_points_earned,
                'total_points_spent' => $delta < 0
                    ? $locked->total_points_spent + abs($delta)
                    : $locked->total_points_spent,
            ])->save();

            $entry = PointsLedger::query()->create([
                'user_id' => $locked->id,
                'type' => $type->value,
                'delta' => $delta,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'meta' => $meta === [] ? null : $meta,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);

            // Keep the caller's in-memory model in step.
            $user->setAttribute('points_balance', $balanceAfter);

            return $entry;
        });
    }

    // ─── Earning ──────────────────────────────────────────────────────────────

    /**
     * Once-per-day check-in.
     *
     * The idempotency key is date-scoped, so a second call the same day returns
     * the existing credit instead of paying again.
     */
    public function checkIn(User $user): PointsLedger
    {
        $settings = $this->settings->checkIn();

        if (! $settings['enabled']) {
            throw new RewardLimitException('Daily check-in is turned off right now.');
        }

        $points = $settings['points'];
        $key = sprintf('checkin:%d:%s', $user->id, now()->toDateString());

        if (PointsLedger::query()->where('idempotency_key', $key)->exists()) {
            throw RewardLimitException::alreadyCheckedIn();
        }

        return $this->credit(
            $user,
            LedgerType::CheckIn,
            $points,
            'Daily check-in',
            $key,
            'check_in',
            null,
        );
    }

    /**
     * Whether the user has already checked in today.
     */
    public function hasCheckedInToday(User $user): bool
    {
        return PointsLedger::query()
            ->where('idempotency_key', sprintf('checkin:%d:%s', $user->id, now()->toDateString()))
            ->exists();
    }

    /**
     * Record a rewarded-ad impression and pay for it.
     *
     * The client supplies an idempotency key per impression, so a retry on a
     * flaky connection cannot mint points. The daily cap is the first line of
     * defence against a scripted client.
     *
     * @param  array<string, mixed>  $meta
     */
    public function claimAdReward(
        User $user,
        string $placement,
        string $idempotencyKey,
        ?string $deviceId = null,
        ?string $ip = null,
        array $meta = [],
    ): AdEvent {
        $existing = AdEvent::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $settings = $this->settings->adReward();

        if (! $settings['enabled']) {
            throw new RewardLimitException('Ad rewards are turned off right now.');
        }

        $limit = $settings['daily_limit'];
        $claimedToday = AdEvent::query()
            ->where('user_id', $user->id)
            ->where('status', AdEventStatus::Verified->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($claimedToday >= $limit) {
            throw RewardLimitException::dailyAds($limit);
        }

        $points = $settings['points'];

        return DB::transaction(function () use ($user, $placement, $idempotencyKey, $deviceId, $ip, $meta, $points): AdEvent {
            $event = AdEvent::query()->create([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'placement' => $placement,
                'provider' => $meta['provider'] ?? null,
                'reward_points' => $points,
                // Auto-verified here; Phase 5 swaps this for the ad network's
                // server-side verification callback.
                'status' => AdEventStatus::Verified->value,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta === [] ? null : $meta,
                'ip_address' => $ip,
            ]);

            $this->credit(
                $user,
                LedgerType::AdReward,
                $points,
                'Rewarded ad',
                'ad:'.$idempotencyKey,
                'ad_event',
                $event->id,
                ['placement' => $placement],
            );

            return $event;
        });
    }

    /**
     * How many rewarded ads the user has already claimed today.
     */
    public function adsClaimedToday(User $user): int
    {
        return AdEvent::query()
            ->where('user_id', $user->id)
            ->where('status', AdEventStatus::Verified->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    // ─── Watch time ───────────────────────────────────────────────────────────

    /**
     * Watch-reward points credited today, for the daily cap.
     */
    public function watchPointsToday(User $user): int
    {
        return (int) PointsLedger::query()
            ->where('user_id', $user->id)
            ->where('type', LedgerType::WatchReward->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('delta');
    }

    /**
     * The watch-to-earn meter: seconds banked toward the next reward.
     *
     * The app's reward ring starts from `seconds` and keeps counting locally,
     * so the ring carries on from one video to the next and across app
     * restarts instead of starting at zero.
     *
     * @return array{enabled: bool, seconds: int, interval_seconds: int, points_per_interval: int, earned_today: int, daily_cap: int, capped: bool}
     */
    public function watchRewardProgress(User $user): array
    {
        $settings = $this->settings->watchReward();
        $interval = $settings['interval_seconds'];
        $perInterval = $settings['points'];
        $cap = $settings['daily_cap'];
        $earnedToday = $this->watchPointsToday($user);

        $carry = (int) WatchRewardState::query()
            ->where('user_id', $user->id)
            ->value('carry_seconds');

        return [
            'enabled' => $settings['enabled'],
            'seconds' => min($carry, $interval - 1),
            'interval_seconds' => $interval,
            'points_per_interval' => $perInterval,
            'earned_today' => $earnedToday,
            'daily_cap' => $cap,
            'capped' => $earnedToday + $perInterval > $cap,
        ];
    }

    // ─── Summary ──────────────────────────────────────────────────────────────

    /**
     * Everything the wallet screen needs, in one payload.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $cashOut = $this->settings->cashOut();
        $checkIn = $this->settings->checkIn();
        $adReward = $this->settings->adReward();
        $perUnit = $cashOut['points_per_unit'];
        $minimum = $cashOut['min_points'];

        return [
            'points_balance' => (int) $user->points_balance,
            'total_points_earned' => (int) $user->total_points_earned,
            'total_points_spent' => (int) $user->total_points_spent,
            'points_per_currency_unit' => $perUnit,
            'currency' => $cashOut['currency'],
            'cash_value' => round($user->points_balance / $perUnit, RewardSettings::decimalsFor($cashOut['currency'])),
            'min_withdrawal_points' => $minimum,
            'can_withdraw' => $user->points_balance >= $minimum,
            'check_in_enabled' => $checkIn['enabled'],
            'check_in_available' => $checkIn['enabled'] && ! $this->hasCheckedInToday($user),
            'check_in_points' => $checkIn['points'],
            'ad_reward_enabled' => $adReward['enabled'],
            'ad_reward_points' => $adReward['points'],
            'ads_claimed_today' => $this->adsClaimedToday($user),
            'daily_ad_limit' => $adReward['daily_limit'],
            'watch_reward' => $this->watchRewardProgress($user),
            'payout_methods' => array_map(
                fn (array $method): array => array_diff_key($method, ['enabled' => true]),
                $this->settings->payoutMethods(enabledOnly: true),
            ),
        ];
    }
}
