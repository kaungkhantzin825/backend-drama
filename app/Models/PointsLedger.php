<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One immutable line of the points ledger.
 *
 * There is deliberately no `updated_at`: rows are inserted once and never
 * changed. Any attempt to update or delete throws, so a future refactor cannot
 * silently rewrite a user's financial history.
 *
 * @property int $id
 * @property int $user_id
 * @property LedgerType $type
 * @property int $delta
 * @property int $balance_after
 * @property string|null $description
 * @property string|null $idempotency_key
 */
class PointsLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'points_ledger';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'delta',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'meta',
        'idempotency_key',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'delta' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('The points ledger is append-only.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('The points ledger is append-only.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
