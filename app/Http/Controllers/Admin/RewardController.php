<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerType;
use App\Http\Controllers\Controller;
use App\Models\AdEvent;
use App\Models\PointsLedger;
use App\Models\Withdrawal;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of the points economy: aggregate stats plus the raw ledger.
 */
class RewardController extends Controller
{
    /**
     * GET /admin/api/rewards/stats
     */
    public function stats(): JsonResponse
    {
        $today = now()->startOfDay();

        return ApiResponse::success('OK', [
            // Sum of every live balance — the platform's total liability.
            'points_outstanding' => (int) \App\Models\User::query()->sum('points_balance'),
            'earned_today' => (int) PointsLedger::query()
                ->where('delta', '>', 0)
                ->where('created_at', '>=', $today)
                ->sum('delta'),
            'spent_today' => (int) abs(PointsLedger::query()
                ->where('delta', '<', 0)
                ->where('created_at', '>=', $today)
                ->sum('delta')),
            'ads_today' => (int) AdEvent::query()->where('created_at', '>=', $today)->count(),
            'ads_verified_today' => (int) AdEvent::query()
                ->where('status', 'verified')
                ->where('created_at', '>=', $today)
                ->count(),
            'pending_withdrawals' => (int) Withdrawal::query()->where('status', 'pending')->count(),
            'ledger_entries' => (int) PointsLedger::query()->count(),
            // Queried through the base query builder on purpose: the Eloquent
            // model casts `type` to the LedgerType enum, which would break the
            // raw GROUP BY row and leave tryFrom() receiving an enum.
            'by_type' => DB::table('points_ledger')
                ->selectRaw('type, COUNT(*) as entries, COALESCE(SUM(delta), 0) as net')
                ->groupBy('type')
                ->get()
                ->map(fn ($row): array => [
                    'type' => $row->type,
                    'label' => LedgerType::tryFrom($row->type)?->label() ?? $row->type,
                    'entries' => (int) $row->entries,
                    'net' => (int) $row->net,
                ])->all(),
        ]);
    }

    /**
     * GET /admin/api/rewards/ledger?type=&user_id=&page=
     */
    public function ledger(Request $request): JsonResponse
    {
        $paginator = PointsLedger::query()
            ->with('user:id,name,email')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('id')
            ->paginate(30)
            ->through(fn (PointsLedger $entry): array => [
                'id' => $entry->id,
                'user' => $entry->user === null ? null : [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'email' => $entry->user->email,
                ],
                'type' => $entry->type->value,
                'type_label' => $entry->type->label(),
                'delta' => $entry->delta,
                'balance_after' => $entry->balance_after,
                'description' => $entry->description,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Ledger loaded.', $paginator);
    }
}
