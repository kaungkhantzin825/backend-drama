<?php

namespace App\Services;

use App\Enums\AdEventStatus;
use App\Enums\TrafficFlagReason;
use App\Enums\TrafficFlagStatus;
use App\Models\AdEvent;
use App\Models\Device;
use App\Models\PointsLedger;
use App\Models\TrafficFlag;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Suspicious-traffic detection and blocking.
 *
 * Two detection paths:
 *  - **real time** — `noteRequest()` counts requests per IP per minute in the
 *    cache and flags a flood the moment it crosses the threshold;
 *  - **scheduled** — `scan()` (every 10 minutes, `traffic:scan`) looks for
 *    account farms and reward farming in the database.
 *
 * Flags are reviewed in the admin panel. Blocking an IP or device adds it to a
 * cached blocklist checked by the BlockSuspiciousTraffic middleware; blocking a
 * user bans the account. With `traffic.auto_block` on, high-severity flags are
 * blocked as soon as they are raised.
 */
class TrafficGuard
{
    public const BLOCKLIST_CACHE = 'traffic:blocklist:v1';

    // ─── Real time ───────────────────────────────────────────────────────────

    /**
     * Count one request against its IP's per-minute budget.
     */
    public function noteRequest(?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        $limit = (int) config('traffic.ip_requests_per_minute');
        $key = 'traffic:ipm:'.$ip.':'.now()->format('YmdHi');

        Cache::add($key, 0, 120);
        $count = (int) Cache::increment($key);

        // Flag exactly once per minute, when the budget is first exceeded —
        // not on every request after it.
        if ($count === $limit + 1) {
            $this->flag(TrafficFlagReason::IpFlood, $ip, 'high', [
                'requests_in_minute' => $count,
                'limit' => $limit,
                'minute' => now()->format('Y-m-d H:i'),
            ]);
        }
    }

    /**
     * Whether this IP or device is on the blocklist.
     */
    public function isBlocked(?string $ip, ?string $deviceId): bool
    {
        $list = $this->blocklist();

        return ($ip !== null && isset($list['ip'][$ip]))
            || ($deviceId !== null && isset($list['device'][$deviceId]));
    }

    /**
     * @return array{ip: array<string, true>, device: array<string, true>}
     */
    private function blocklist(): array
    {
        return Cache::remember(self::BLOCKLIST_CACHE, 600, function (): array {
            $list = ['ip' => [], 'device' => []];

            TrafficFlag::query()
                ->where('status', TrafficFlagStatus::Blocked->value)
                ->whereIn('subject_type', ['ip', 'device'])
                ->get(['subject_type', 'subject'])
                ->each(function (TrafficFlag $flag) use (&$list): void {
                    $list[$flag->subject_type][$flag->subject] = true;
                });

            return $list;
        });
    }

    // ─── Flags ───────────────────────────────────────────────────────────────

    /**
     * Raise (or re-raise) a flag. A repeat bumps `hits` and `last_seen_at` but
     * never re-opens a flag an admin already dismissed.
     *
     * @param  array<string, mixed>  $details
     */
    public function flag(
        TrafficFlagReason $reason,
        string $subject,
        string $severity,
        array $details = [],
        ?int $userId = null,
    ): TrafficFlag {
        $flag = DB::transaction(function () use ($reason, $subject, $severity, $details, $userId): TrafficFlag {
            $existing = TrafficFlag::query()
                ->where('subject_type', $reason->subjectType())
                ->where('subject', $subject)
                ->where('reason', $reason->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'hits' => $existing->hits + 1,
                    'details' => $details,
                    'severity' => $severity,
                    'last_seen_at' => now(),
                ])->save();

                return $existing;
            }

            return TrafficFlag::query()->create([
                'subject_type' => $reason->subjectType(),
                'subject' => $subject,
                'user_id' => $userId,
                'reason' => $reason->value,
                'severity' => $severity,
                'details' => $details,
                'status' => TrafficFlagStatus::Open->value,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        });

        if (config('traffic.auto_block') && $severity === 'high' && $flag->status === TrafficFlagStatus::Open) {
            $this->block($flag, null);
        }

        return $flag;
    }

    /**
     * Block the flagged subject. `$admin` is null for an automatic block.
     */
    public function block(TrafficFlag $flag, ?User $admin): TrafficFlag
    {
        $this->resolve($flag, TrafficFlagStatus::Blocked, $admin);

        if ($flag->subject_type === 'user') {
            $user = User::query()->find((int) $flag->subject);

            if ($user !== null && ! $user->isStaff()) {
                $user->forceFill([
                    'banned_at' => now(),
                    'ban_reason' => 'Traffic: '.$flag->reason->label(),
                ])->save();
                $user->tokens()->delete();
            }
        }

        return $flag;
    }

    public function dismiss(TrafficFlag $flag, User $admin): TrafficFlag
    {
        return $this->resolve($flag, TrafficFlagStatus::Dismissed, $admin);
    }

    /**
     * Lift a block. The flag stays on record as dismissed.
     */
    public function unblock(TrafficFlag $flag, User $admin): TrafficFlag
    {
        $wasBlocked = $flag->status === TrafficFlagStatus::Blocked;

        $this->resolve($flag, TrafficFlagStatus::Dismissed, $admin);

        if ($wasBlocked && $flag->subject_type === 'user') {
            User::query()->whereKey((int) $flag->subject)
                ->update(['banned_at' => null, 'ban_reason' => null]);
        }

        return $flag;
    }

    private function resolve(TrafficFlag $flag, TrafficFlagStatus $status, ?User $admin): TrafficFlag
    {
        $flag->forceFill([
            'status' => $status->value,
            'resolved_by' => $admin?->id,
            'resolved_at' => now(),
        ])->save();

        Cache::forget(self::BLOCKLIST_CACHE);

        return $flag->refresh();
    }

    /**
     * @param  array{status?: string|null, reason?: string|null}  $filters
     */
    public function search(array $filters = []): LengthAwarePaginator
    {
        return TrafficFlag::query()
            ->with(['user:id,name,email', 'resolver:id,name'])
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($filters['reason'] ?? null, fn ($q, string $r) => $q->where('reason', $r))
            ->orderByRaw("FIELD(severity, 'high', 'medium', 'low')")
            ->latest('last_seen_at')
            ->paginate(30);
    }

    // ─── Scheduled scan ──────────────────────────────────────────────────────

    /**
     * Run every database detector. Returns how many flags each raised.
     *
     * @return array<string, int>
     */
    public function scan(): array
    {
        return [
            TrafficFlagReason::ManyAccountsPerDevice->value => $this->scanAccountsPerDevice(),
            TrafficFlagReason::ManyAccountsPerIp->value => $this->scanAccountsPerIp(),
            TrafficFlagReason::EmulatorEarning->value => $this->scanEmulatorEarning(),
            TrafficFlagReason::AdCapStreak->value => $this->scanAdCapStreaks(),
        ];
    }

    private function scanAccountsPerDevice(): int
    {
        $max = (int) config('traffic.max_accounts_per_device');

        $rows = User::query()
            ->whereNull('role')
            ->whereNotNull('device_id')
            ->select('device_id', DB::raw('COUNT(*) as accounts'))
            ->groupBy('device_id')
            ->havingRaw('COUNT(*) > ?', [$max])
            ->get();

        foreach ($rows as $row) {
            $this->flag(
                TrafficFlagReason::ManyAccountsPerDevice,
                (string) $row->device_id,
                $row->accounts > $max * 2 ? 'high' : 'medium',
                ['accounts' => (int) $row->accounts, 'limit' => $max],
            );
        }

        return $rows->count();
    }

    private function scanAccountsPerIp(): int
    {
        $max = (int) config('traffic.max_accounts_per_ip');

        $rows = User::query()
            ->whereNull('role')
            ->whereNotNull('last_ip')
            ->where('last_seen_at', '>=', now()->subHours((int) config('traffic.scan_window_hours')))
            ->select('last_ip', DB::raw('COUNT(*) as accounts'))
            ->groupBy('last_ip')
            ->havingRaw('COUNT(*) > ?', [$max])
            ->get();

        foreach ($rows as $row) {
            // Shared networks (offices, carrier NAT) legitimately put many
            // people behind one IP, so this is never auto-blocked.
            $this->flag(
                TrafficFlagReason::ManyAccountsPerIp,
                (string) $row->last_ip,
                'medium',
                ['accounts' => (int) $row->accounts, 'limit' => $max],
            );
        }

        return $rows->count();
    }

    private function scanEmulatorEarning(): int
    {
        $since = now()->subHours((int) config('traffic.scan_window_hours'));

        $devices = Device::query()
            ->where('is_emulator', true)
            ->whereNotNull('user_id')
            ->get(['device_id', 'user_id']);

        $flagged = 0;

        foreach ($devices as $device) {
            $earned = (int) PointsLedger::query()
                ->where('user_id', $device->user_id)
                ->where('delta', '>', 0)
                ->where('created_at', '>=', $since)
                ->sum('delta');

            if ($earned <= 0) {
                continue;
            }

            $this->flag(
                TrafficFlagReason::EmulatorEarning,
                (string) $device->device_id,
                'high',
                ['points_earned' => $earned, 'window_hours' => (int) config('traffic.scan_window_hours')],
                (int) $device->user_id,
            );
            $flagged++;
        }

        return $flagged;
    }

    private function scanAdCapStreaks(): int
    {
        $days = max(2, (int) config('traffic.ad_cap_streak_days'));
        $cap = (int) config('rewards.daily_ad_limit');

        $streak = null;

        // Users who hit the cap on *every* one of the last N days.
        for ($i = 0; $i < $days; $i++) {
            $day = now()->subDays($i)->startOfDay();

            $hitCap = AdEvent::query()
                ->where('status', AdEventStatus::Verified->value)
                ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                ->whereNotNull('user_id')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) >= ?', [$cap])
                ->pluck('user_id')
                ->all();

            $streak = $streak === null ? $hitCap : array_values(array_intersect($streak, $hitCap));

            if ($streak === []) {
                return 0;
            }
        }

        foreach ($streak as $userId) {
            $this->flag(
                TrafficFlagReason::AdCapStreak,
                (string) $userId,
                'medium',
                ['days' => $days, 'daily_cap' => $cap],
                (int) $userId,
            );
        }

        return count($streak);
    }
}
