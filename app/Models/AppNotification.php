<?php

namespace App\Models;

use App\Enums\NotificationType;
use Database\Factories\AppNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a user's in-app inbox.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationType $type
 * @property string $title
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $pushed_at
 */
class AppNotification extends Model
{
    /** @use HasFactory<AppNotificationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
        'pushed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark as read. Idempotent — the first timestamp wins.
     */
    public function markRead(): bool
    {
        if ($this->isRead()) {
            return false;
        }

        return $this->forceFill(['read_at' => now()])->save();
    }

    /**
     * @param  Builder<AppNotification>  $query
     * @return Builder<AppNotification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @param  Builder<AppNotification>  $query
     * @return Builder<AppNotification>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * The deep-link route the client should open.
     */
    public function routeName(): string
    {
        return $this->type->route();
    }
}
