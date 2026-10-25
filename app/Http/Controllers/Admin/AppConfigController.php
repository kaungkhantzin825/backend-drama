<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppConfigRequest;
use App\Services\AppConfigService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Admin editor for the remote config: ads (switch, interval, rules, waterfall,
 * A/B experiment) and release policy (versions, maintenance).
 */
class AppConfigController extends Controller
{
    public function __construct(private readonly AppConfigService $config) {}

    /**
     * GET /admin/api/app-config
     */
    public function show(): JsonResponse
    {
        return ApiResponse::success('OK', $this->config->adminView());
    }

    /**
     * PUT /admin/api/app-config
     */
    public function update(AppConfigRequest $request): JsonResponse
    {
        return ApiResponse::success(
            'App config saved. Apps pick it up on their next launch.',
            $this->config->update($request->validated()),
        );
    }
}
