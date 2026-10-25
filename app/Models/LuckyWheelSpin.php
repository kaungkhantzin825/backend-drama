<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lucky-wheel spin.
 *
 * @property int $id
 * @property int $user_id
 * @property int $prize_points
 * @property int $segment_index
 */
class LuckyWheelSpin extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'prize_points', 'segment_index'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prize_points' => 'integer',
            'segment_index' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
