<?php

use App\Models\TrafficFlag;
use App\Services\TrafficGuard;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Traffic monitoring
|--------------------------------------------------------------------------
|
| Needs the scheduler running in production:
|   * * * * * cd /path/to/backend-drama && php artisan schedule:run
|
*/

Artisan::command('traffic:scan', function (TrafficGuard $guard) {
    foreach ($guard->scan() as $reason => $count) {
        $this->line(sprintf('%-28s %d', $reason, $count));
    }
})->purpose('Scan for account farms and reward farming, and raise traffic flags');

Artisan::command('traffic:prune', function () {
    $keep = config('traffic.retention');

    $deleted = [
        'api_traffic_hourly' => DB::table('api_traffic_hourly')
            ->where('hour', '<', now()->subDays($keep['api_hourly']))->delete(),
        'ad_traffic_daily' => DB::table('ad_traffic_daily')
            ->where('date', '<', now()->subDays($keep['ad_daily'])->toDateString())->delete(),
        'user_daily_activity' => DB::table('user_daily_activity')
            ->where('date', '<', now()->subDays($keep['user_daily'])->toDateString())->delete(),
        // Blocked flags are kept: they *are* the blocklist.
        'traffic_flags' => TrafficFlag::query()
            ->where('status', 'dismissed')
            ->where('resolved_at', '<', now()->subDays($keep['resolved_flags']))->delete(),
    ];

    foreach ($deleted as $table => $count) {
        $this->line(sprintf('%-22s %d rows pruned', $table, $count));
    }
})->purpose('Delete traffic aggregates older than the retention window');

Schedule::command('traffic:scan')->everyTenMinutes()->withoutOverlapping();
Schedule::command('traffic:prune')->dailyAt('03:30');
