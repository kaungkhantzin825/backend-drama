<?php

namespace App\Http\Middleware;

use App\Services\AppConfigService;
use App\Support\ApiResponse;
use App\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side half of the release policy.
 *
 *  - Maintenance mode → 503 for everything except the endpoints the app needs
 *    to *show* the maintenance screen.
 *  - App older than `app_version.min_supported` → 426 Upgrade Required.
 *
 * The version check only applies when the app sends `X-App-Version`, so curl,
 * health probes and very old builds without the header are not locked out by
 * accident (the app-side check covers the latter).
 */
class EnforceAppVersion
{
    /** Always reachable: the app reads its config here to render the gate. */
    private const EXEMPT_ROUTES = ['api.v1.app-config', 'api.v1.health'];

    public function __construct(private readonly AppConfigService $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        $config = $this->config->base();

        if ($config['maintenance']['enabled']) {
            return ApiResponse::error($config['maintenance']['message'], ['maintenance' => true], 503);
        }

        $version = ClientContext::fromRequest($request)->appVersion;
        $minimum = $config['app_version']['min_supported'];

        if ($version !== '' && $minimum !== '' && version_compare($version, $minimum, '<')) {
            return ApiResponse::error('Please update DramaLoop to keep watching.', [
                'update_required' => true,
                'min_supported' => $minimum,
                'latest' => $config['app_version']['latest'],
            ], 426);
        }

        return $next($request);
    }
}
