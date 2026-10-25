<?php

namespace App\Models;

use App\Enums\TrafficFlagReason;
use App\Enums\TrafficFlagStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suspicious subject (IP, device or user) for one reason.
 *
 * @property int $id
 * @property string $subject_type
 * @property string $subject
 * @property int|null $user_id
 * @property TrafficFlagReason $reason
 * @property string $severity
 * @property array<string, mixed>|null $details
 * @property int $hits
 * @property TrafficFlagStatus $status
 */
class TrafficFlag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject',
        'user_id',
        'reason',
        'severity',
        'details',
        'hits',
        'status',
        'first_seen_at',
        'last_seen_at',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => TrafficFlagReason::class,
            'status' => TrafficFlagStatus::class,
            'details' => 'array',
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
