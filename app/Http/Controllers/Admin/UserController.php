<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin user management: browse, inspect, ban.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * GET /admin/api/users?q=&role=&banned=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'role' => $request->filled('role') ? $request->string('role')->toString() : null,
            'banned' => $request->has('banned') ? $request->boolean('banned') : null,
        ];

        $paginator = $this->users
            ->adminSearch($filters)
            ->paginate(25)
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'is_guest' => $user->isGuest(),
                'points_balance' => (int) $user->points_balance,
                'total_points_earned' => (int) $user->total_points_earned,
                'is_banned' => $user->banned_at !== null,
                'device_id' => $user->device_id,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Users loaded.', $paginator);
    }

    /**
     * GET /admin/api/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->loadCount(['adEvents', 'withdrawals']);

        return ApiResponse::success('OK', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'points_balance' => (int) $user->points_balance,
            'total_points_earned' => (int) $user->total_points_earned,
            'total_points_spent' => (int) $user->total_points_spent,
            'is_banned' => $user->banned_at !== null,
            'ban_reason' => $user->ban_reason,
            'counts' => [
                'ad_events' => $user->ad_events_count,
                'withdrawals' => $user->withdrawals_count,
            ],
            'recent_ledger' => $user->ledger()->limit(10)->get()->map(fn ($e): array => [
                'id' => $e->id,
                'type' => $e->type->value,
                'delta' => $e->delta,
                'balance_after' => $e->balance_after,
                'description' => $e->description,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * POST /admin/api/users/{user}/ban
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        $user->forceFill([
            'banned_at' => now(),
            'ban_reason' => $validated['reason'],
        ])->save();

        // A banned account must not keep an authenticated session on mobile.
        $user->tokens()->delete();

        return ApiResponse::success('User banned.', ['id' => $user->id, 'is_banned' => true]);
    }

    /**
     * DELETE /admin/api/users/{user}/ban
     */
    public function unban(User $user): JsonResponse
    {
        $user->forceFill(['banned_at' => null, 'ban_reason' => null])->save();

        return ApiResponse::success('User unbanned.', ['id' => $user->id, 'is_banned' => false]);
    }
}
