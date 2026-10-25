<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data-access for User records.
 *
 * Repositories isolate the query layer from the services so business rules can
 * be unit-tested against an interface and the raw SQL stays in one place.
 */
class UserRepository
{
    /**
     * Find a user by their Google `sub` claim.
     */
    public function findByGoogleId(string $googleId): ?User
    {
        return User::query()
            ->where('google_id', $googleId)
            ->first();
    }

    /**
     * Find a user by email (case-insensitive on the MySQL collation).
     */
    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->first();
    }

    /**
     * Find a mobile user by the device id they signed up with.
     *
     * This is how a returning guest is recognised: the app sends its stable
     * device id and we look up the account created for it previously.
     */
    public function findGuestByDeviceId(string $deviceId): ?User
    {
        return User::query()
            ->whereNull('google_id')
            ->whereNull('role')
            ->where('device_id', $deviceId)
            ->first();
    }

    /**
     * Persist a new user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    /**
     * Update a user's profile attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user->refresh();
    }

    /**
     * Record that the user was just seen (used for DAU reporting).
     */
    public function touchLastSeen(User $user, ?string $ip = null): void
    {
        $user->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $ip ?? $user->last_ip,
        ])->saveQuietly();
    }

    /**
     * Query builder for the admin user list.
     *
     * @param  array{q?: string, role?: string, banned?: bool}  $filters
     * @return Builder<User>
     */
    public function adminSearch(array $filters = []): Builder
    {
        return User::query()
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.str_replace('%', '\%', $term).'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('device_id', 'like', $like);
                });
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when(isset($filters['banned']) && $filters['banned'] !== null,
                fn (Builder $query, bool $banned) => $query->where('banned_at', $banned ? '!=' : null, null))
            ->latest();
    }

    /**
     * Count users grouped by creation day — feeds the admin DAU chart.
     *
     * @return array<string, int>
     */
    public function countByDay(int $days = 30): array
    {
        $rows = DB::table('users')
            ->whereNull('role')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[$day] = (int) ($rows[$day] ?? 0);
        }

        return $out;
    }
}
