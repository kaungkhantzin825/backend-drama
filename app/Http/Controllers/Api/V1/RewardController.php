<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RewardLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdEventRequest;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Earning and spending points.
 *
 * Every endpoint here is server-authoritative: the client asks for an action,
 * the server decides the amount and records it in the ledger.
 */
class RewardController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * GET /api/v1/wallet
     */
    public function wallet(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', $this->wallet->summary($request->user()));
    }

    /**
     * GET /api/v1/wallet/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $paginator = $request->user()
            ->ledger()
            ->paginate(25)
            ->through(fn ($entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'type_label' => $entry->type->label(),
                'delta' => $entry->delta,
                'balance_after' => $entry->balance_after,
                'description' => $entry->description,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Ledger loaded.', $paginator);
    }

    /**
     * POST /api/v1/rewards/check-in
     */
    public function checkIn(Request $request): JsonResponse
    {
        try {
            $entry = $this->wallet->checkIn($request->user());
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Checked in.', [
            'points_awarded' => $entry->delta,
            'wallet' => $this->wallet->summary($request->user()->refresh()),
        ]);
    }

    /**
     * POST /api/v1/ads/events
     */
    public function adEvent(AdEventRequest $request): JsonResponse
    {
        try {
            $event = $this->wallet->claimAdReward(
                $request->user(),
                $request->string('placement')->toString(),
                $request->string('idempotency_key')->toString(),
                $request->input('device_id'),
                $request->ip(),
                (array) $request->input('meta', []),
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 429);
        }

        return ApiResponse::success('Ad reward credited.', [
            'ad_event_id' => $event->id,
            'points_awarded' => $event->reward_points,
            'status' => $event->status->value,
            'wallet' => $this->wallet->summary($request->user()->refresh()),
        ]);
    }
}
