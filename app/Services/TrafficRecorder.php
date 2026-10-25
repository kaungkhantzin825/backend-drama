<?php

namespace App\Services;

use App\Models\User;
use App\Support\ClientContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes the traffic aggregates: API requests per hour, user activity per day,
 * and ad waterfall events per day.
 *
 * Every write is one `INSERT ... ON DUPLICATE KEY UPDATE n = n + k` against a
 * bucket row, so recording never reads first and never grows a raw log.
 *
 * Recording is best-effort by design: analytics must never break a request,
 * so every public method swallows (and reports) its own failures.
 */
class TrafficRecorder
{
    /** Ad SDK events the app may report, mapped to their counter column. */
    public const AD_EVENTS = [
        'request' => 'requests',
        'fill' => 'fills',
        'no_fill' => 'no_fills',
        'timeout' => 'timeouts',
        'impression' => 'impressions',
        'click' => 'clicks',
        'show_failed' => 'show_failures',
        'paid' => 'revenue_micros',
    ];

    // ─── API ─────────────────────────────────────────────────────────────────

    public function recordApiRequest(Request $request, Response $response, int $durationMs): void
    {
        if (! config('traffic.api_tracking')) {
            return;
        }

        $this->safely(function () use ($request, $response, $durationMs): void {
            $client = ClientContext::fromRequest($request);
            $slow = $durationMs >= (int) config('traffic.slow_request_ms') ? 1 : 0;

            $this->bump('api_traffic_hourly', [
                'hour' => now()->startOfHour()->toDateTimeString(),
                'route' => mb_substr($request->route()?->getName() ?? 'unmatched', 0, 96),
                'method' => $request->method(),
                'status_class' => intdiv($response->getStatusCode(), 100),
                'platform' => $client->platform,
                'app_version' => $client->appVersion,
            ], [
                'requests' => 1,
                'total_ms' => max(0, $durationMs),
                'slow_requests' => $slow,
            ]);
        });
    }

    // ─── User activity ───────────────────────────────────────────────────────

    /**
     * One authenticated API request. Also keeps `users.last_seen_at/last_ip`
     * fresh (at most every five minutes), which the per-IP fraud scan reads.
     */
    public function recordUserRequest(User $user, ClientContext $client): void
    {
        $this->safely(function () use ($user, $client): void {
            $this->bumpUser($user, $client, ['requests' => 1]);

            if (Cache::add('traffic:seen:'.$user->id, 1, 300)) {
                $user->forceFill([
                    'last_seen_at' => now(),
                    'last_ip' => $client->ip ?? $user->last_ip,
                ])->saveQuietly();
            }
        });
    }

    /** The app reported a new session (cold start, or back after a long pause). */
    public function recordSession(User $user, ClientContext $client): void
    {
        $this->safely(fn () => $this->bumpUser($user, $client, ['sessions' => 1]));
    }

    public function addWatchSeconds(User $user, int $seconds, ?ClientContext $client = null): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->safely(fn () => $this->bumpUser(
            $user,
            $client ?? new ClientContext('', '', null, '', null),
            ['watch_seconds' => $seconds],
        ));
    }

    // ─── Ads ─────────────────────────────────────────────────────────────────

    /**
     * Record a batch of ad SDK events from the app.
     *
     * Events are folded into buckets in PHP first, so a batch of 50 events for
     * the same unit becomes one upsert, not 50.
     *
     * @param  list<array{event: string, format: string, placement?: string|null, unit_id?: string|null, tier?: int|null, value_micros?: int|null}>  $events
     * @return int how many events were accepted
     */
    public function recordAdEvents(array $events, ClientContext $client, ?User $user): int
    {
        $buckets = [];
        $impressions = 0;
        $accepted = 0;

        foreach ($events as $event) {
            $column = self::AD_EVENTS[$event['event'] ?? ''] ?? null;

            if ($column === null) {
                continue;
            }

            $keys = [
                'date' => now()->toDateString(),
                'platform' => $client->platform,
                'format' => mb_substr((string) $event['format'], 0, 16),
                'placement' => mb_substr((string) ($event['placement'] ?? ''), 0, 32),
                'unit_id' => mb_substr((string) ($event['unit_id'] ?? ''), 0, 64),
                'tier' => max(1, min(10, (int) ($event['tier'] ?? 1))),
                'ab_group' => $client->abGroup,
            ];

            $id = implode('|', $keys);
            $buckets[$id]['keys'] = $keys;
            $buckets[$id]['inc'][$column] = ($buckets[$id]['inc'][$column] ?? 0)
                + ($column === 'revenue_micros' ? max(0, (int) ($event['value_micros'] ?? 0)) : 1);

            if ($column === 'impressions') {
                $impressions++;
            }

            $accepted++;
        }

        $this->safely(function () use ($buckets, $impressions, $client, $user): void {
            foreach ($buckets as $bucket) {
                $this->bump('ad_traffic_daily', $bucket['keys'], $bucket['inc']);
            }

            if ($user !== null && $impressions > 0) {
                $this->bumpUser($user, $client, ['ad_impressions' => $impressions]);
            }
        });

        return $accepted;
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, int>  $increments
     */
    private function bumpUser(User $user, ClientContext $client, array $increments): void
    {
        $keys = ['user_id' => $user->id, 'date' => now()->toDateString()];

        // Platform/version/group describe the user's *latest* state that day,
        // so they are overwritten — but only when the request carried them.
        $describe = array_filter([
            'platform' => $client->platform,
            'app_version' => $client->appVersion,
            'ab_group' => $client->abGroup,
        ], fn (string $value): bool => $value !== '');

        $this->bump('user_daily_activity', $keys, $increments, $describe);
    }

    /**
     * Insert the bucket, or add the increments to it.
     *
     * @param  array<string, mixed>  $keys        unique-index columns
     * @param  array<string, int>  $increments    counter column => amount
     * @param  array<string, mixed>  $overwrite   plain columns to set
     */
    private function bump(string $table, array $keys, array $increments, array $overwrite = []): void
    {
        $update = [];

        foreach ($increments as $column => $amount) {
            // Qualified with the table name so MySQL's upsert row alias can
            // never shadow the existing value. `(int)` keeps it injection-safe.
            $update[$column] = DB::raw(sprintf('`%s`.`%s` + %d', $table, $column, (int) $amount));
        }

        foreach (array_keys($overwrite) as $column) {
            $update[] = $column;
        }

        DB::table($table)->upsert(
            [array_merge($keys, $overwrite, $increments)],
            array_keys($keys),
            $update,
        );
    }

    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
