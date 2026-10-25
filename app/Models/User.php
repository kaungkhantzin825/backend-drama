<?php

namespace App\Models;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user of the platform.
 *
 * The same table backs two very different populations, separated by `role`:
 *
 *  - Mobile users  → `role` is NULL. Authenticated with Sanctum tokens.
 *  - Staff accounts → `role` is 'admin' or 'editor'. Authenticated through the
 *    `admin` session guard for the Inertia panel.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property string|null $device_id
 * @property string|null $role
 * @property int $points_balance
 * @property int $total_points_earned
 * @property int $total_points_spent
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar_url',
        'device_id',
        'role',
        'points_balance',
        'total_points_earned',
        'total_points_spent',
        'email_verified_at',
        'last_seen_at',
        'last_ip',
        'banned_at',
        'ban_reason',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
            'points_balance' => 'integer',
            'total_points_earned' => 'integer',
            'total_points_spent' => 'integer',
            'role' => UserRole::class,
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Devices that have been registered against this account.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The most recently active device for this account.
     */
    public function primaryDevice(): HasMany
    {
        return $this->hasMany(Device::class)->latest('last_active_at');
    }

    /**
     * Append-only points history.
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(PointsLedger::class)->latest('id');
    }

    /**
     * Ad impressions reported by this user.
     */
    public function adEvents(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }

    /**
     * Cash-out requests.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class)->latest('id');
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    /**
     * The authentication provider used by this account.
     *
     * An account linked to Google always resolves to `google`, even if it was
     * originally created as a guest and later upgraded.
     */
    public function getAuthProviderAttribute(): AuthProvider
    {
        return $this->google_id !== null
            ? AuthProvider::Google
            : AuthProvider::Guest;
    }

    /**
     * True when this account is a guest (no Google link).
     */
    public function isGuest(): bool
    {
        return $this->google_id === null;
    }

    // ─── Roles ────────────────────────────────────────────────────────────────

    /**
     * True when the account may sign in to the admin panel.
     */
    public function isStaff(): bool
    {
        return $this->role !== null;
    }

    /**
     * True when the account holds the `admin` role.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * True when the account holds the `editor` role.
     */
    public function isEditor(): bool
    {
        return $this->role === UserRole::Editor;
    }

    // ─── Query scopes ─────────────────────────────────────────────────────────

    /**
     * Only mobile users (no admin role).
     *
     * @param  Builder<User>  $query
     */
    public function scopeMobileUsers(Builder $query): void
    {
        $query->whereNull('role');
    }

    /**
     * Only staff accounts.
     *
     * @param  Builder<User>  $query
     */
    public function scopeStaff(Builder $query): void
    {
        $query->whereNotNull('role');
    }

    /**
     * Accounts created through the Google sign-in flow.
     *
     * @param  Builder<User>  $query
     */
    public function scopeGoogleAccounts(Builder $query): void
    {
        $query->whereNotNull('google_id');
    }
}
