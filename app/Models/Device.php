<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered mobile device.
 *
 * One row per app install. A device may be shared by several accounts (the
 * `user_id` column points at the account that last used it), which is exactly
 * the pattern Phase 4's withdrawal fraud checks look for.
 *
 * @property int $id
 * @property string $device_id
 * @property int|null $user_id
 * @property string|null $fcm_token
 * @property Platform $platform
 * @property string|null $app_version
 * @property bool $is_emulator
 * @property \Illuminate\Support\Carbon|null $last_active_at
 */
class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
        'user_id',
        'fcm_token',
        'platform',
        'app_version',
        'model',
        'os_version',
        'is_emulator',
        'is_rooted',
        'device_hash',
        'ip_address',
        'user_agent',
        'last_active_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'is_emulator' => 'boolean',
            'is_rooted' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * The account that most recently used this device.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the client reported an emulator fingerprint.
     */
    public function looksLikeEmulator(): bool
    {
        return $this->is_emulator;
    }
}
