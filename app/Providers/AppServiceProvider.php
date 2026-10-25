<?php

namespace App\Providers;

use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Push\AccessTokenProvider;
use App\Services\Push\ServiceAccountTokenProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Google verifier is configured from env, so bind it as a
        // singleton rather than constructing it at each call site.
        $this->app->singleton(GoogleTokenVerifier::class, function ($app): GoogleTokenVerifier {
            $ids = array_filter([
                config('services.google.client_id'),
                config('services.google.client_id_ios'),
            ]);

            return new GoogleTokenVerifier($ids);
        });

        // FCM access tokens are cached for their lifetime, so the provider must
        // be a singleton too. The interface binding is what lets tests swap in
        // a stub instead of calling Google.
        $this->app->singleton(AccessTokenProvider::class, ServiceAccountTokenProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Named rate limiters.
     *
     * Reward, withdrawal and ad-verification endpoints get their own, much
     * tighter limits in later phases. The `api` limiter is the default for
     * every route in routes/api.php.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Sign-in endpoints — keyed by IP as well as user, because at this
        // point there is no authenticated user yet.
        RateLimiter::for('auth', function (Request $request): Limit {
            return [
                Limit::perMinute(20)->by('auth:'.$request->ip()),
                Limit::perHour(100)->by('auth:'.$request->ip()),
            ];
        });

        // Reward endpoints — these are the fraud-sensitive ones (Phase 3).
        RateLimiter::for('rewards', function (Request $request): Limit {
            return Limit::perMinute(30)->by('rewards:'.($request->user()?->id ?: $request->ip()));
        });

        // Withdrawal requests (Phase 4).
        RateLimiter::for('withdraw', function (Request $request): Limit {
            return Limit::perMinute(10)->by('withdraw:'.($request->user()?->id ?: $request->ip()));
        });

        // Ad event logging — high volume, so it is generous but still bounded.
        RateLimiter::for('ad-events', function (Request $request): Limit {
            return Limit::perMinute(120)->by('ads:'.$request->ip());
        });

        // Playback heartbeats. The player pings every ~10s, so this is looser
        // than the reward endpoints but still caps a runaway client.
        RateLimiter::for('progress', function (Request $request): Limit {
            return Limit::perMinute(120)->by('progress:'.($request->user()?->id ?: $request->ip()));
        });

        // Server-side ad verification callbacks (Phase 5).
        RateLimiter::for('ad-verify', function (Request $request): Limit {
            return Limit::perMinute(60)->by('adverify:'.$request->ip());
        });
    }
}
