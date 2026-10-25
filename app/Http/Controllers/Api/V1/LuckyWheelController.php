<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RewardLimitException;
use App\Http\Controllers\Controller;
use App\Services\LuckyWheelService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lucky wheel.
 *
 * The client asks for the segments, animates a spin, then calls `spin` — which
 * is the only thing that actually decides a prize.
 */
class LuckyWheelController extends Controller
{
    public function __construct(private readonly LuckyWheelService $wheel) {}

    /**
     * GET /api/v1/rewards/lucky-wheel
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success('Wheel loaded.', [
            'segments' => $this->wheel->segments(),
            'daily_limit' => $this->wheel->dailyLimit(),
            'spins_remaining' => $this->wheel->spinsRemaining($user),
            'spins_used_today' => $this->wheel->spinsUsedToday($user),
        ]);
    }

    /**
     * POST /api/v1/rewards/lucky-wheel/spin
     */
    public function spin(Request $request): JsonResponse
    {
        try {
            $result = $this->wheel->spin($request->user());
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), [
                'spins_remaining' => $this->wheel->spinsRemaining($request->user()),
            ], 422);
        }

        return ApiResponse::success(
            $result['prize_points'] > 0
                ? sprintf('You won %d points!', $result['prize_points'])
                : 'No prize this time.',
            $result,
        );
    }
}
