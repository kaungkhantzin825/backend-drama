<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\RewardLimitException;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin payout queue: approve, reject (with refund) or mark paid.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /**
     * GET /admin/api/withdrawals?status=
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->withdrawals
            ->adminSearch([
                'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            ])
            ->through(fn (Withdrawal $w): array => [
                'id' => $w->id,
                'user' => $w->user === null ? null : [
                    'id' => $w->user->id,
                    'name' => $w->user->name,
                    'email' => $w->user->email,
                ],
                'points_amount' => $w->points_amount,
                'currency' => $w->currency,
                'amount' => $w->amountLabel(),
                'display_amount' => $w->displayAmount(),
                'method' => $w->method,
                'method_name' => $this->withdrawals->methodName($w->method),
                'account_details' => $w->account_details,
                'status' => $w->status->value,
                'status_label' => $w->status->label(),
                'admin_note' => $w->admin_note,
                'created_at' => $w->created_at?->toIso8601String(),
                'processed_at' => $w->processed_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Withdrawals loaded.', $paginator);
    }

    /**
     * POST /admin/api/withdrawals/{withdrawal}/approve
     */
    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        try {
            $updated = $this->withdrawals->approve(
                $withdrawal,
                Auth::guard('admin')->user(),
                $request->input('note'),
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Withdrawal approved.', ['status' => $updated->status->value]);
    }

    /**
     * POST /admin/api/withdrawals/{withdrawal}/reject
     */
    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $updated = $this->withdrawals->reject(
                $withdrawal,
                Auth::guard('admin')->user(),
                $validated['note'],
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Withdrawal rejected and points refunded.', [
            'status' => $updated->status->value,
        ]);
    }

    /**
     * POST /admin/api/withdrawals/{withdrawal}/paid
     */
    public function markPaid(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        try {
            $updated = $this->withdrawals->markPaid(
                $withdrawal,
                Auth::guard('admin')->user(),
                $request->input('note'),
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Withdrawal marked paid.', ['status' => $updated->status->value]);
    }
}
