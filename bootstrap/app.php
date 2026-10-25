<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ─── Inertia ─────────────────────────────────────────────────────────
        // Appends the HandleInertiaRequests middleware to the web group so
        // every Inertia page resolves the shared props and partial reloads.
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // ─── Admin panel ─────────────────────────────────────────────────────
        // The admin panel lives under /admin and uses the `admin` session
        // guard. Aliases keep the route definitions readable.
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AuthenticateAdmin::class,
            'admin.role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // The mobile API is stateless (Sanctum tokens), so it must not carry
        // session cookies or CSRF tokens.
        //
        // NOTE: `statefulApi()` is deliberately NOT enabled. It would make the
        // `web` session guard apply to API requests, and Sanctum's guard
        // checks that guard before the bearer token — meaning a stale session
        // could authenticate an API call and bypass token revocation on
        // logout. The Flutter client is Bearer-token only.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            // Order matters: record every request (blocked ones included, so
            // the traffic report shows the attack), then turn away blocked
            // IPs/devices/accounts, then apply the release policy.
            \App\Http\Middleware\TrackApiTraffic::class,
            \App\Http\Middleware\BlockSuspiciousTraffic::class,
            \App\Http\Middleware\EnforceAppVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ─── Unified API envelope ────────────────────────────────────────────
        // Every error the mobile client *and* the admin panel can receive must
        // use the same { success, message, data } shape as the success paths.
        // Both API surfaces are covered: /api/v1 (mobile) and /admin/api
        // (admin panel JSON).
        $exceptions->shouldRenderJsonWhen(
            fn (\Illuminate\Http\Request $request, \Throwable $e): bool =>
                $request->is('api/*')
                || $request->is('admin/api/*')
                || $request->expectsJson()
        );

        // NOTE: the framework invokes render callbacks as ($exception, $request).
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->is('admin/api/*')) {
                return null;
            }

            return app(\App\Support\ExceptionRenderer::class)->render($e);
        });
    })->create();
