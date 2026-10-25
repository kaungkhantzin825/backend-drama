<?php

/*
|--------------------------------------------------------------------------
| Traffic monitoring
|--------------------------------------------------------------------------
|
| Thresholds for the API/ad/user traffic recorders and the suspicious-traffic
| detector. Like config/rewards.php, tuning these is a config change, not a
| code change.
|
*/

return [

    // Master switch for per-request API traffic recording (hourly buckets).
    'api_tracking' => (bool) env('TRAFFIC_API_TRACKING', true),

    // Requests slower than this count as "slow" in the API report.
    'slow_request_ms' => (int) env('TRAFFIC_SLOW_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Suspicious traffic
    |--------------------------------------------------------------------------
    */

    // One IP sending more than this many API requests in a minute is flagged.
    'ip_requests_per_minute' => (int) env('TRAFFIC_IP_RPM', 300),

    // More guest/Google accounts than this on one device id is flagged.
    'max_accounts_per_device' => (int) env('TRAFFIC_MAX_ACCOUNTS_DEVICE', 3),

    // More accounts than this last seen on one IP (in the scan window) is flagged.
    'max_accounts_per_ip' => (int) env('TRAFFIC_MAX_ACCOUNTS_IP', 15),

    // Hitting the daily rewarded-ad cap this many days in a row is flagged.
    'ad_cap_streak_days' => (int) env('TRAFFIC_AD_CAP_STREAK', 3),

    // How far back the scheduled scan looks.
    'scan_window_hours' => (int) env('TRAFFIC_SCAN_WINDOW_HOURS', 24),

    // When true, a high-severity flag blocks its IP/device immediately.
    // Off by default: start by reviewing flags, then turn this on.
    'auto_block' => (bool) env('TRAFFIC_AUTO_BLOCK', false),

    /*
    |--------------------------------------------------------------------------
    | Retention (days) — pruned daily by `traffic:prune`
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'api_hourly' => (int) env('TRAFFIC_KEEP_API_DAYS', 90),
        'ad_daily' => (int) env('TRAFFIC_KEEP_AD_DAYS', 400),
        'user_daily' => (int) env('TRAFFIC_KEEP_USER_DAYS', 400),
        'resolved_flags' => (int) env('TRAFFIC_KEEP_FLAG_DAYS', 180),
    ],

];
