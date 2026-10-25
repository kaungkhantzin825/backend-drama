<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdTrafficRequest;
use App\Services\TrafficRecorder;
use App\Support\ApiResponse;
use App\Support\ClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Telemetry the app reports: ad waterfall events and sessions.
 *
 * Analytics only — nothing here moves points. Rewarded-ad payouts still go
 * through POST /ads/events, which has its own fraud checks.
 */
class TrafficController extends Controller
{
    public function __construct(private readonly TrafficRecorder $recorder) {}

    /**
     * POST /api/v1/ads/traffic
     *
     * Public, because ads load before sign-in finishes; when a bearer token is
     * present the impressions also count towards that user's daily activity.
     */
    public function adTraffic(AdTrafficRequest $request): JsonResponse
    {
        $accepted = $this->recorder->recordAdEvents(
            $request->validated('events'),
            ClientContext::fromRequest($request),
            Auth::guard('sanctum')->user(),
        );

        return ApiResponse::success('Recorded.', ['accepted' => $accepted]);
    }

    /**
     * POST /api/v1/activity/session
     *
     * The app calls this on cold start and when it returns from a long pause.
     */
    public function session(Request $request): JsonResponse
    {
        $this->recorder->recordSession($request->user(), ClientContext::fromRequest($request));

        return ApiResponse::success('Recorded.');
    }
}
