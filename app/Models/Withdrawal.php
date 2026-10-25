<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Database\Factories\WithdrawalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash-out request.
 *
 * @property int $id
 * @property int $user_id
 * @property int $points_amount
 * @property string $currency
 * @property int $amount_cents
 * @property string $method
 * @property array<string, mixed>|null $account_details
 * @property WithdrawalStatus $status
 * @property string|null $admin_note
 */
class Withdrawal extends Model
{
    /** @use HasFactory<WithdrawalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'points_amount',
        'currency',
        'amount_cents',
        'method',
        'account_details',
        'status',
        'admin_note',
        'processed_by',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'points_amount' => 'integer',
            'amount_cents' => 'integer',
            'account_details' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Payout amount as a display string, e.g. "12.50". */
    public function amountLabel(): string
    {
        return number_format($this->amount_cents / 100, 2);
    }

    /**
     * Amount with its currency, e.g. "$12.50".
     *
     * `amountLabel()` is deliberately bare because the client pairs it with a
     * separate `currency` field. Anywhere the amount is embedded in prose —
     * notification bodies, admin notes — this is the one to use, otherwise it
     * reads as "your 12.50 request".
     */
    public function displayAmount(): string
    {
        $symbol = match (strtoupper((string) $this->currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => '',
        };

        if ($symbol !== '') {
            return $symbol.$this->amountLabel();
        }

        return trim(((string) $this->currency).' '.$this->amountLabel());
    }
}
