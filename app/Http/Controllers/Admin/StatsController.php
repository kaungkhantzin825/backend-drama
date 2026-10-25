<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Models\AdEvent;
use App\Models\Drama;
use App\Models\PointsLedger;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Aggregate counters for the admin overview screen.
 */
class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now()->startOfDay();

        return ApiResponse::success('OK', [
            'users' => [
                'total' => (int) User::query()->whereNull('role')->count(),
                'new_today' => (int) User::query()->whereNull('role')->where('created_at', '>=', $today)->count(),
                'banned' => (int) User::query()->whereNotNull('banned_at')->count(),
            ],
            'content' => [
                'dramas' => (int) Drama::query()->count(),
                'published' => (int) Drama::query()->where('status', 'published')->count(),
            ],
            'economy' => [
                'points_outstanding' => (int) User::query()->sum('points_balance'),
                'earned_today' => (int) PointsLedger::query()->where('delta', '>', 0)->where('created_at', '>=', $today)->sum('delta'),
                'spent_today' => (int) abs(PointsLedger::query()->where('delta', '<', 0)->where('created_at', '>=', $today)->sum('delta')),
                'ledger_entries' => (int) PointsLedger::query()->count(),
            ],
            'ads' => [
                'today' => (int) AdEvent::query()->where('created_at', '>=', $today)->count(),
                'verified_today' => (int) AdEvent::query()->where('status', 'verified')->where('created_at', '>=', $today)->count(),
            ],
            'withdrawals' => [
                'pending' => (int) Withdrawal::query()->where('status', WithdrawalStatus::Pending->value)->count(),
                'paid_total_cents' => (int) Withdrawal::query()->where('status', WithdrawalStatus::Paid->value)->sum('amount_cents'),
            ],
        ]);
    }
}
