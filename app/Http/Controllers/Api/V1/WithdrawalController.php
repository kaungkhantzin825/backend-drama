<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientPointsException;
use App\Exceptions\RewardLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WithdrawalRequestRequest;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash-out requests from the mobile wallet.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /**
     * GET /api/v1/withdrawals
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->withdrawals
            ->forUser($request->user())
            ->through(fn ($withdrawal): array => [
                'id' => $withdrawal->id,
                'points_amount' => $withdrawal->points_amount,
                'currency' => $withdrawal->currency,
                'amount' => $withdrawal->amountLabel(),
                'method' => $withdrawal->method,
                'status' => $withdrawal->status->value,
                'status_label' => $withdrawal->status->label(),
                'admin_note' => $withdrawal->admin_note,
                'created_at' => $withdrawal->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Withdrawals loaded.', $paginator);
    }

    /**
     * POST /api/v1/withdrawals
     *
     * Debits the whole balance and queues the payout.
     */
    public function store(WithdrawalRequestRequest $request): JsonResponse
    {
        try {
            $withdrawal = $this->withdrawals->request(
                $request->user(),
                $request->string('method')->toString(),
                $request->accountDetails(),
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        } catch (InsufficientPointsException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Withdrawal requested.', [
            'id' => $withdrawal->id,
            'points_amount' => $withdrawal->points_amount,
            'amount' => $withdrawal->amountLabel(),
            'status' => $withdrawal->status->value,
        ], 201);
    }
}
