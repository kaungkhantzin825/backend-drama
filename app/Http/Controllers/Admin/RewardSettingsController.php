<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardSettingsRequest;
use App\Services\RewardSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The rewards economy: watch minutes, check-in, ads, cash-out, payment
 * methods and the lucky wheel. Changes apply to the next request.
 */
class RewardSettingsController extends Controller
{
    public function __construct(private readonly RewardSettings $settings) {}

    /**
     * GET /admin/api/reward-settings
     */
    public function show(): JsonResponse
    {
        return ApiResponse::success('OK', $this->settings->all());
    }

    /**
     * PUT /admin/api/reward-settings
     */
    public function update(RewardSettingsRequest $request): JsonResponse
    {
        return ApiResponse::success('Reward settings saved.', $this->settings->update($request->settings()));
    }
}
