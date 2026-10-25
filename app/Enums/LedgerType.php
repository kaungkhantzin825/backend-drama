<?php

namespace App\Enums;

/**
 * Every reason a user's balance can change.
 *
 * The ledger is append-only: a correction is a new row of type `adjust`,
 * never an edit of history.
 */
enum LedgerType: string
{
    case AdReward = 'ad_reward';
    case CheckIn = 'check_in';
    case WatchReward = 'watch_reward';
    case LuckyWheel = 'lucky_wheel';
    // Legacy: episodes and unlocks are gone, but old ledger rows keep the type.
    case EpisodeUnlock = 'episode_unlock';
    case Withdrawal = 'withdrawal';
    case WithdrawalRefund = 'withdrawal_refund';
    case AdminAdjust = 'admin_adjust';

    /** True when this type adds points to the balance. */
    public function isCredit(): bool
    {
        return in_array($this, [
            self::AdReward,
            self::CheckIn,
            self::WatchReward,
            self::LuckyWheel,
            self::WithdrawalRefund,
            self::AdminAdjust,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::AdReward => 'Ad reward',
            self::CheckIn => 'Daily check-in',
            self::WatchReward => 'Watch reward',
            self::LuckyWheel => 'Lucky wheel',
            self::EpisodeUnlock => 'Episode unlock',
            self::Withdrawal => 'Withdrawal',
            self::WithdrawalRefund => 'Withdrawal refund',
            self::AdminAdjust => 'Admin adjustment',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
