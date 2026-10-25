<?php

namespace App\Services;

use App\Enums\TrafficFlagStatus;
use App\Models\TrafficFlag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side of traffic monitoring: the admin "Traffic" screen and CSV export.
 *
 * Every report is computed from the aggregate tables, so even a 90-day range
 * reads a few hundred rows, not millions of requests.
 *
 * Daily series are zero-filled, so a chart never skips a quiet day.
 */
class TrafficReportService
{
    public const REPORTS = ['activity', 'api', 'ads', 'waterfall', 'ab'];

    // ─── User activity ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function activity(int $days): array
    {
        [$from, $to] = $this->range($days);

        $daily = DB::table('user_daily_activity')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, COUNT(*) as dau, SUM(sessions) as sessions, SUM(requests) as requests,
                SUM(watch_seconds) as watch_seconds, SUM(ad_impressions) as ad_impressions')
            ->get()
            ->keyBy(fn ($r) => (string) $r->date);

        $newUsers = User::query()
            ->whereNull('role')
            ->whereBetween('created_at', [$from, $to->endOfDay()])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as n')
            ->groupBy('date')
            ->pluck('n', 'date');

        $series = $this->days($from, $to)->map(function (string $date) use ($daily, $newUsers): array {
            $row = $daily->get($date);

            return [
                'date' => $date,
                'dau' => (int) ($row->dau ?? 0),
                'new_users' => (int) ($newUsers[$date] ?? 0),
                'sessions' => (int) ($row->sessions ?? 0),
                'requests' => (int) ($row->requests ?? 0),
                'watch_minutes' => (int) round(($row->watch_seconds ?? 0) / 60),
                'ad_impressions' => (int) ($row->ad_impressions ?? 0),
            ];
        })->values();

        $today = now()->toDateString();

        return [
            'summary' => [
                'dau_today' => (int) DB::table('user_daily_activity')->where('date', $today)->count(),
                'mau' => (int) DB::table('user_daily_activity')
                    ->where('date', '>=', now()->subDays(29)->toDateString())
                    ->distinct()
                    ->count('user_id'),
                'new_users_today' => (int) ($newUsers[$today] ?? 0),
                'avg_sessions_per_user' => $this->ratio($series->sum('sessions'), $series->sum('dau')),
                'avg_watch_minutes_per_user' => $this->ratio($series->sum('watch_minutes'), $series->sum('dau')),
                'next_day_return_rate' => $this->nextDayReturnRate($from, $to),
            ],
            'series' => $series,
            'platforms' => $this->breakdown('platform', $from, $to),
            'versions' => $this->breakdown('app_version', $from, $to),
        ];
    }

    /**
     * Of the users active on day D, the share also active on day D+1, across
     * the range. A cheap, honest retention signal from the daily table.
     */
    private function nextDayReturnRate(CarbonImmutable $from, CarbonImmutable $to, ?string $abGroup = null): float
    {
        $base = DB::table('user_daily_activity as a')
            ->whereBetween('a.date', [$from->toDateString(), $to->subDay()->toDateString()])
            ->when($abGroup !== null, fn ($q) => $q->where('a.ab_group', $abGroup));

        $total = (clone $base)->count();

        if ($total === 0) {
            return 0.0;
        }

        $returned = (clone $base)
            ->join('user_daily_activity as b', function ($join): void {
                $join->on('b.user_id', '=', 'a.user_id')
                    ->whereRaw('b.date = DATE_ADD(a.date, INTERVAL 1 DAY)');
            })
            ->count();

        return round($returned / $total * 100, 1);
    }

    /**
     * Distinct active users per value of `$column` over the range.
     *
     * @return list<array{value: string, users: int}>
     */
    private function breakdown(string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('user_daily_activity')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy($column)
            ->selectRaw("{$column} as value, COUNT(DISTINCT user_id) as users")
            ->orderByDesc('users')
            ->limit(10)
            ->get()
            ->map(fn ($r): array => ['value' => $r->value === '' ? 'unknown' : (string) $r->value, 'users' => (int) $r->users])
            ->all();
    }

    // ─── API ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function api(int $days): array
    {
        [$from, $to] = $this->range($days);
        $window = [$from->toDateTimeString(), $to->endOfDay()->toDateTimeString()];

        $daily = DB::table('api_traffic_hourly')
            ->whereBetween('hour', $window)
            ->groupByRaw('DATE(hour)')
            ->selectRaw('DATE(hour) as date, SUM(requests) as requests, SUM(total_ms) as total_ms,
                SUM(CASE WHEN status_class = 4 THEN requests ELSE 0 END) as client_errors,
                SUM(CASE WHEN status_class = 5 THEN requests ELSE 0 END) as server_errors,
                SUM(slow_requests) as slow')
            ->get()
            ->keyBy(fn ($r) => (string) $r->date);

        $series = $this->days($from, $to)->map(function (string $date) use ($daily): array {
            $row = $daily->get($date);
            $requests = (int) ($row->requests ?? 0);

            return [
                'date' => $date,
                'requests' => $requests,
                'client_errors' => (int) ($row->client_errors ?? 0),
                'server_errors' => (int) ($row->server_errors ?? 0),
                'slow' => (int) ($row->slow ?? 0),
                'avg_ms' => $requests > 0 ? (int) round($row->total_ms / $requests) : 0,
            ];
        })->values();

        $routes = DB::table('api_traffic_hourly')
            ->whereBetween('hour', $window)
            ->groupBy('route', 'method')
            ->selectRaw('route, method, SUM(requests) as requests, SUM(total_ms) as total_ms, SUM(slow_requests) as slow,
                SUM(CASE WHEN status_class >= 4 THEN requests ELSE 0 END) as errors')
            ->orderByDesc('requests')
            ->limit(25)
            ->get()
            ->map(fn ($r): array => [
                'route' => $r->route,
                'method' => $r->method,
                'requests' => (int) $r->requests,
                'avg_ms' => (int) round($r->total_ms / max(1, $r->requests)),
                'slow' => (int) $r->slow,
                'error_rate' => $this->ratio((int) $r->errors * 100, (int) $r->requests),
            ])
            ->all();

        $thisHour = (int) DB::table('api_traffic_hourly')
            ->where('hour', now()->startOfHour()->toDateTimeString())
            ->sum('requests');

        $total = (int) $series->sum('requests');

        return [
            'summary' => [
                'requests' => $total,
                // Rate over the minutes elapsed in the current hour bucket.
                'requests_per_minute_now' => $this->ratio($thisHour, max(1, now()->minute + 1)),
                'error_rate' => $this->ratio(($series->sum('client_errors') + $series->sum('server_errors')) * 100, $total),
                'server_error_rate' => $this->ratio($series->sum('server_errors') * 100, $total),
                'avg_ms' => $total > 0 ? (int) round($series->sum(fn ($d) => $d['avg_ms'] * $d['requests']) / $total) : 0,
                'slow_requests' => (int) $series->sum('slow'),
            ],
            'series' => $series,
            'routes' => $routes,
        ];
    }

    // ─── Ads ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function ads(int $days): array
    {
        [$from, $to] = $this->range($days);
        $dates = [$from->toDateString(), $to->toDateString()];

        $daily = DB::table('ad_traffic_daily')
            ->whereBetween('date', $dates)
            ->groupBy('date')
            ->selectRaw('date, SUM(requests) as requests, SUM(fills) as fills, SUM(impressions) as impressions,
                SUM(clicks) as clicks, SUM(revenue_micros) as revenue_micros')
            ->get()
            ->keyBy(fn ($r) => (string) $r->date);

        $series = $this->days($from, $to)->map(function (string $date) use ($daily): array {
            $row = $daily->get($date);

            return [
                'date' => $date,
                'requests' => (int) ($row->requests ?? 0),
                'fills' => (int) ($row->fills ?? 0),
                'impressions' => (int) ($row->impressions ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'revenue' => round(($row->revenue_micros ?? 0) / 1_000_000, 2),
            ];
        })->values();

        $formats = DB::table('ad_traffic_daily')
            ->whereBetween('date', $dates)
            ->groupBy('format')
            ->selectRaw('format, SUM(requests) as requests, SUM(fills) as fills, SUM(impressions) as impressions,
                SUM(clicks) as clicks, SUM(revenue_micros) as revenue_micros')
            ->get()
            ->map(fn ($r): array => $this->adMetrics($r) + ['format' => $r->format])
            ->all();

        $placements = DB::table('ad_traffic_daily')
            ->whereBetween('date', $dates)
            ->where('impressions', '>', 0)
            ->groupBy('format', 'placement')
            ->selectRaw('format, placement, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(revenue_micros) as revenue_micros')
            ->orderByDesc('impressions')
            ->get()
            ->map(fn ($r): array => [
                'format' => $r->format,
                'placement' => $r->placement === '' ? '—' : $r->placement,
                'impressions' => (int) $r->impressions,
                'clicks' => (int) $r->clicks,
                'revenue' => round($r->revenue_micros / 1_000_000, 2),
            ])
            ->all();

        return [
            'summary' => [
                'requests' => (int) $series->sum('requests'),
                'fill_rate' => $this->ratio($series->sum('fills') * 100, $series->sum('requests')),
                'impressions' => (int) $series->sum('impressions'),
                'ctr' => $this->ratio($series->sum('clicks') * 100, $series->sum('impressions')),
                'revenue' => round($series->sum('revenue'), 2),
                'ecpm' => $this->ratio($series->sum('revenue') * 1000, $series->sum('impressions')),
            ],
            'series' => $series,
            'formats' => $formats,
            'placements' => $placements,
            'waterfall' => $this->waterfall($days),
        ];
    }

    /**
     * Per-unit performance in waterfall order — what you need to decide
     * whether to reorder, add or drop a tier.
     *
     * @return list<array<string, mixed>>
     */
    public function waterfall(int $days): array
    {
        [$from, $to] = $this->range($days);

        return DB::table('ad_traffic_daily')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('unit_id', '!=', '')
            ->groupBy('platform', 'format', 'tier', 'unit_id')
            ->selectRaw('platform, format, tier, unit_id, SUM(requests) as requests, SUM(fills) as fills,
                SUM(no_fills) as no_fills, SUM(timeouts) as timeouts, SUM(impressions) as impressions,
                SUM(clicks) as clicks, SUM(revenue_micros) as revenue_micros')
            ->orderBy('platform')
            ->orderBy('format')
            ->orderBy('tier')
            ->get()
            ->map(fn ($r): array => [
                'platform' => $r->platform === '' ? 'unknown' : $r->platform,
                'format' => $r->format,
                'tier' => (int) $r->tier,
                'unit_id' => $r->unit_id,
                'no_fills' => (int) $r->no_fills,
                'timeouts' => (int) $r->timeouts,
            ] + $this->adMetrics($r))
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function adMetrics(object $r): array
    {
        $revenue = ($r->revenue_micros ?? 0) / 1_000_000;

        return [
            'requests' => (int) ($r->requests ?? 0),
            'fills' => (int) ($r->fills ?? 0),
            'fill_rate' => $this->ratio(($r->fills ?? 0) * 100, $r->requests ?? 0),
            'impressions' => (int) ($r->impressions ?? 0),
            'clicks' => (int) ($r->clicks ?? 0),
            'ctr' => $this->ratio(($r->clicks ?? 0) * 100, $r->impressions ?? 0),
            'revenue' => round($revenue, 2),
            'ecpm' => $this->ratio($revenue * 1000, $r->impressions ?? 0),
        ];
    }

    // ─── A/B experiment ──────────────────────────────────────────────────────

    /**
     * Compare experiment variants on engagement and ad revenue.
     *
     * @return array<string, mixed>
     */
    public function ab(int $days): array
    {
        [$from, $to] = $this->range($days);
        $dates = [$from->toDateString(), $to->toDateString()];

        $activity = DB::table('user_daily_activity')
            ->whereBetween('date', $dates)
            ->where('ab_group', '!=', '')
            ->groupBy('ab_group')
            ->selectRaw('ab_group, COUNT(DISTINCT user_id) as users, COUNT(*) as user_days,
                SUM(sessions) as sessions, SUM(watch_seconds) as watch_seconds, SUM(ad_impressions) as impressions')
            ->get()
            ->keyBy('ab_group');

        $ads = DB::table('ad_traffic_daily')
            ->whereBetween('date', $dates)
            ->where('ab_group', '!=', '')
            ->groupBy('ab_group')
            ->selectRaw('ab_group, SUM(requests) as requests, SUM(fills) as fills, SUM(impressions) as impressions,
                SUM(clicks) as clicks, SUM(revenue_micros) as revenue_micros')
            ->get()
            ->keyBy('ab_group');

        $groups = $activity->keys()->merge($ads->keys())->unique()->sort()->values();

        $experiment = app(AppConfigService::class)->adminView()['ad_experiment'];

        return [
            'experiment' => $experiment,
            'variants' => $groups->map(function (string $group) use ($activity, $ads, $from, $to): array {
                $a = $activity->get($group);
                $ad = $ads->get($group);
                $userDays = (int) ($a->user_days ?? 0);
                $revenue = ($ad->revenue_micros ?? 0) / 1_000_000;

                return [
                    'group' => $group,
                    'users' => (int) ($a->users ?? 0),
                    'sessions_per_user_day' => $this->ratio($a->sessions ?? 0, $userDays),
                    'watch_minutes_per_user_day' => $this->ratio(($a->watch_seconds ?? 0) / 60, $userDays),
                    'impressions_per_user_day' => $this->ratio($ad->impressions ?? 0, $userDays),
                    'next_day_return_rate' => $this->nextDayReturnRate($from, $to, $group),
                    'fill_rate' => $this->ratio(($ad->fills ?? 0) * 100, $ad->requests ?? 0),
                    'revenue' => round($revenue, 2),
                    // Average revenue per daily active user.
                    'arpdau' => $this->ratio($revenue, $userDays, 4),
                    'ecpm' => $this->ratio($revenue * 1000, $ad->impressions ?? 0),
                ];
            })->all(),
        ];
    }

    // ─── Overview & export ───────────────────────────────────────────────────

    /**
     * Headline numbers for the top of the Traffic screen.
     *
     * @return array<string, mixed>
     */
    public function headline(): array
    {
        return [
            'open_flags' => (int) TrafficFlag::query()->where('status', TrafficFlagStatus::Open->value)->count(),
            'high_flags' => (int) TrafficFlag::query()
                ->where('status', TrafficFlagStatus::Open->value)
                ->where('severity', 'high')
                ->count(),
            'blocked' => (int) TrafficFlag::query()->where('status', TrafficFlagStatus::Blocked->value)->count(),
        ];
    }

    /**
     * Rows for a CSV download: a header row, then one row per line.
     *
     * @return list<list<string|int|float>>
     */
    public function csvRows(string $report, int $days): array
    {
        $rows = match ($report) {
            'activity' => $this->activity($days)['series']->all(),
            'api' => $this->api($days)['series']->all(),
            'ads' => $this->ads($days)['series']->all(),
            'waterfall' => $this->waterfall($days),
            'ab' => $this->ab($days)['variants'],
        };

        if ($rows === []) {
            return [['no data']];
        }

        return [array_keys($rows[0]), ...array_map('array_values', $rows)];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(int $days): array
    {
        $days = max(1, min(90, $days));
        $to = CarbonImmutable::now()->startOfDay();

        return [$to->subDays($days - 1), $to];
    }

    /**
     * @return Collection<int, string>
     */
    private function days(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $out = collect();

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $out->push($day->toDateString());
        }

        return $out;
    }

    private function ratio(int|float $part, int|float $whole, int $precision = 2): float
    {
        return $whole > 0 ? round($part / $whole, $precision) : 0.0;
    }
}
