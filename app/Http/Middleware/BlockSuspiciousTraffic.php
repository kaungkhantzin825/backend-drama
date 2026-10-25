<?php

namespace App\Http\Middleware;

use App\Services\TrafficGuard;
use App\Support\ApiResponse;
use App\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away blocked IPs, blocked devices and banned accounts.
 *
 * The account check matters on its own: a banned guest could otherwise sign
 * in again from the same device and get a fresh token. Resolving the bearer
 * token here costs nothing extra — Sanctum caches the user for the request,
 * so `auth:sanctum` reuses it.
 */
class BlockSuspiciousTraffic
{
    public function __construct(private readonly TrafficGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = ClientContext::fromRequest($request);

        if ($this->guard->isBlocked($client->ip, $client->deviceId)) {
            return ApiResponse::error('Access from this device or network has been blocked.', null, 403);
        }

        $user = $request->bearerToken() !== null ? Auth::guard('sanctum')->user() : null;

        if ($user !== null && $user->banned_at !== null) {
            return ApiResponse::error('This account has been suspended.', null, 403);
        }

        return $next($request);
    }
}
