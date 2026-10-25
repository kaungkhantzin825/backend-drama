<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppConfigService;
use App\Support\ApiResponse;
use App\Support\ClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remote configuration the app fetches on cold start.
 *
 * Public on purpose: the app needs its ad setup, update policy and maintenance
 * state before the guest token exists. Nothing here is secret — ad unit ids
 * ship inside every app binary anyway.
 */
class AppConfigController extends Controller
{
    public function __construct(private readonly AppConfigService $config) {}

    /**
     * GET /api/v1/app-config
     *
     * The device id (X-Device-Id header) picks the A/B experiment variant.
     */
    public function show(Request $request): JsonResponse
    {
        $deviceId = ClientContext::fromRequest($request)->deviceId;

        return ApiResponse::success('OK', $this->config->forClient($deviceId))
            // `private`: with an experiment running, the payload differs per
            // device, so a shared cache must never hand one device's variant to
            // another. The server-side cache is what keeps this endpoint cheap.
            ->header('Cache-Control', 'private, max-age=300');
    }
}
