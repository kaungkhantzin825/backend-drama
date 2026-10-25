<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far a viewer got in one video.
 *
 * Watch-time rewards are not tracked here: they run per user across videos,
 * see {@see WatchRewardState}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $drama_id
 * @property int $position_seconds
 * @property int $duration_seconds
 * @property bool $is_completed
 * @property \Illuminate\Support\Carbon|null $last_watched_at
 */
class WatchProgress extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'drama_id',
        'position_seconds',
        'duration_seconds',
        'is_completed',
        'last_watched_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position_seconds' => 0,
        'duration_seconds' => 0,
        'is_completed' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'is_completed' => 'boolean',
            'last_watched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }

    /**
     * 0–100, and 0 when the duration is not known yet.
     */
    public function percentWatched(): int
    {
        if ($this->duration_seconds <= 0) {
            return 0;
        }

        return (int) min(100, round($this->position_seconds / $this->duration_seconds * 100));
    }

    /**
     * @param  Builder<WatchProgress>  $query
     * @return Builder<WatchProgress>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
