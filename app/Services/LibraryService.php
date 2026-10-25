<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\RewardLimitException;
use App\Models\Drama;
use App\Models\Favorite;
use App\Models\User;
use App\Models\WatchProgress;
use App\Models\WatchRewardState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-viewer library state: progress, history, my list — and the watch-time
 * reward that pays for it.
 *
 * The client only reports "I am at second N of this video, and I watched S
 * seconds since my last report". Everything else — how much of that is
 * believable, whether it completes a paid minute, and how many points remain
 * under the daily cap — is decided here.
 */
class LibraryService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TrafficRecorder $traffic,
    ) {}

    /**
     * Record playback position and pay any newly completed reward intervals.
     *
     * `$watchedSeconds` is the playback time since the client's last
     * heartbeat. Older clients do not send it; their forward progress through
     * the video is used instead.
     *
     * @return array{progress: WatchProgress, points_awarded: int, wallet: array<string, mixed>}
     */
    public function record(
        User $user,
        Drama $drama,
        int $positionSeconds,
        int $durationSeconds,
        ?int $watchedSeconds = null,
    ): array {
        $duration = $durationSeconds > 0 ? $durationSeconds : (int) $drama->duration_seconds;

        if ($duration <= 0) {
            throw new RewardLimitException('The video duration is not known yet.');
        }

        $position = max(0, min($positionSeconds, $duration));

        $progress = WatchProgress::query()->firstOrCreate(
            ['user_id' => $user->id, 'drama_id' => $drama->id],
            ['duration_seconds' => $duration],
        );

        $watched = $watchedSeconds ?? max(0, $position - (int) $progress->position_seconds);

        $metered = $this->payWatchTime($user, $drama, $watched);

        // Watch time for the activity report, after the wall-clock guard.
        $this->traffic->addWatchSeconds($user, $metered['seconds']);

        // Playback only ever moves forward in the history.
        $progress->position_seconds = max((int) $progress->position_seconds, $position);
        $progress->duration_seconds = $duration;
        $progress->is_completed = $progress->position_seconds >= (int) floor($duration * 0.95);
        $progress->last_watched_at = now();
        $progress->save();

        return [
            'progress' => $progress->refresh(),
            'points_awarded' => $metered['awarded'],
            'wallet' => $this->wallet->summary($user->refresh()),
        ];
    }

    /**
     * Bank watch time on the viewer's meter and pay every completed interval.
     *
     * The meter is per user, not per video, so a minute can be completed
     * across several videos. Two guards keep it honest:
     *  - credited time may not run ahead of the wall clock (`credited_until`),
     *    so replaying or inflating heartbeats cannot earn a minute in less
     *    than a minute;
     *  - the daily cap. Once it is reached, nothing is banked until tomorrow.
     *
     * @return array{seconds: int, awarded: int}
     */
    private function payWatchTime(User $user, Drama $drama, int $watched): array
    {
        $interval = max(10, (int) config('rewards.watch_reward_interval_seconds'));
        $perInterval = max(1, (int) config('rewards.watch_reward_points'));
        $cap = max(0, (int) config('rewards.daily_watch_reward_cap'));
        $maxPerPing = max(1, (int) config('rewards.watch_max_seconds_per_ping'));

        WatchRewardState::query()->firstOrCreate(['user_id' => $user->id]);

        return DB::transaction(function () use ($user, $drama, $watched, $interval, $perInterval, $cap, $maxPerPing): array {
            /** @var WatchRewardState $state */
            $state = WatchRewardState::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $now = now()->getTimestamp();

            // An idle gap does not bank allowance beyond one heartbeat's worth.
            $creditedUntil = max(
                $state->credited_until?->getTimestamp() ?? 0,
                $now - $maxPerPing,
            );

            $seconds = max(0, min($watched, $maxPerPing, $now - $creditedUntil));

            $state->credited_until = now()->setTimestamp($creditedUntil + $seconds);

            $affordable = intdiv(max(0, $cap - $this->wallet->watchPointsToday($user)), $perInterval);

            if ($affordable === 0) {
                // Daily cap reached: stop banking, so the meter starts fresh
                // tomorrow instead of paying out a backlog.
                $state->carry_seconds = 0;
                $state->save();

                return ['seconds' => $seconds, 'awarded' => 0];
            }

            $carry = $state->carry_seconds + $seconds;
            $payable = min(intdiv($carry, $interval), $affordable);

            for ($paid = 1; $paid <= $payable; $paid++) {
                $this->wallet->credit(
                    $user,
                    LedgerType::WatchReward,
                    $perInterval,
                    sprintf(
                        'Watched %s of videos',
                        $interval >= 60 ? intdiv($interval, 60).' min' : $interval.' s',
                    ),
                    // One key per interval, so a duplicate ping cannot double-pay.
                    sprintf('watch:%d:%d', $user->id, $state->intervals_paid + $paid),
                    'drama',
                    $drama->id,
                );
            }

            $state->intervals_paid += $payable;
            $state->carry_seconds = $payable === intdiv($carry, $interval)
                ? $carry - $payable * $interval
                // Capped part-way through: drop the unpaid backlog.
                : 0;
            $state->save();

            return ['seconds' => $seconds, 'awarded' => $payable * $perInterval];
        });
    }

    /**
     * Videos still in progress, newest first.
     *
     * @return Collection<int, WatchProgress>
     */
    public function continueWatching(User $user, int $limit = 10): Collection
    {
        return WatchProgress::query()
            ->forUser($user)
            ->with('drama')
            ->where('is_completed', false)
            ->where('position_seconds', '>', 0)
            // The id tiebreaker matters: several rows can share the same
            // timestamp, and without it the rail order is non-deterministic.
            ->orderByDesc('last_watched_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Everything the viewer has touched, newest first.
     */
    public function history(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return WatchProgress::query()
            ->forUser($user)
            ->with('drama')
            ->orderByDesc('last_watched_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Add or remove a drama from the list. Returns the new state.
     */
    public function toggleFavorite(User $user, Drama $drama): bool
    {
        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('drama_id', $drama->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        Favorite::query()->create([
            'user_id' => $user->id,
            'drama_id' => $drama->id,
        ]);

        return true;
    }

    public function isFavorite(User $user, Drama $drama): bool
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->where('drama_id', $drama->id)
            ->exists();
    }

    /**
     * The viewer's list, restricted to titles still published.
     */
    public function favorites(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Drama::query()
            ->published()
            ->whereIn('id', Favorite::query()
                ->where('user_id', $user->id)
                ->select('drama_id'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
