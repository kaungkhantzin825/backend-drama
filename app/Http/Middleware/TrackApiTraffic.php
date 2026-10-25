<?php

namespace App\Http\Middleware;

use App\Services\TrafficGuard;
use App\Services\TrafficRecorder;
use App\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every mobile API request into the traffic aggregates.
 *
 * `handle()` only does the cheap real-time part (the per-IP flood counter).
 * The database writes happen in `terminate()`, after the response has been
 * sent, so recording adds nothing to the latency the user sees.
 */
class TrackApiTraffic
{
    public function __construct(
        private readonly TrafficRecorder $recorder,
        private readonly TrafficGuard $guard,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('traffic_started_at', microtime(true));

        $this->guard->noteRequest($request->ip());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = (float) $request->attributes->get('traffic_started_at', microtime(true));
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->recorder->recordApiRequest($request, $response, $durationMs);

        // Only set for routes behind `auth:sanctum`, which is what we want:
        // activity belongs to a known account.
        $user = $request->user();

        if ($user !== null && $user->role === null) {
            $this->recorder->recordUserRequest($user, ClientContext::fromRequest($request));
        }
    }
}
