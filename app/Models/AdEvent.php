<?php

namespace App\Models;

use App\Enums\AdEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reported ad impression.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $device_id
 * @property string $placement
 * @property int $reward_points
 * @property AdEventStatus $status
 * @property string $idempotency_key
 */
class AdEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'device_id',
        'placement',
        'provider',
        'reward_points',
        'status',
        'idempotency_key',
        'meta',
        'ip_address',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AdEventStatus::class,
            'reward_points' => 'integer',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
