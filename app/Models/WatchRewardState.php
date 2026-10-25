<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A viewer's running watch-time meter.
 *
 * Watch time is banked here whatever video it was spent on, so 50 seconds of
 * one video plus 10 seconds of the next completes a paid minute. Only
 * LibraryService writes it, under a row lock.
 *
 * @property int $id
 * @property int $user_id
 * @property int $carry_seconds
 * @property int $intervals_paid
 * @property Carbon|null $credited_until
 */
class WatchRewardState extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'carry_seconds',
        'intervals_paid',
        'credited_until',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'carry_seconds' => 0,
        'intervals_paid' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'carry_seconds' => 'integer',
            'intervals_paid' => 'integer',
            'credited_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
