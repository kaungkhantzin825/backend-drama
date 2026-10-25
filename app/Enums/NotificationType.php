<?php

namespace App\Enums;

/**
 * What a notification is about.
 *
 * The type drives the icon and colour in the mobile inbox and lets the client
 * deep-link to the right screen without parsing the body text.
 */
enum NotificationType: string
{
    case WithdrawalApproved = 'withdrawal_approved';
    case WithdrawalRejected = 'withdrawal_rejected';
    case WithdrawalPaid = 'withdrawal_paid';
    case RewardGranted = 'reward_granted';
    // Legacy episode types: nothing sends them now, but stored rows keep them.
    case EpisodeUnlocked = 'episode_unlocked';
    case NewEpisode = 'new_episode';
    case System = 'system';

    /** The screen the client should open when the notification is tapped. */
    public function route(): string
    {
        return match ($this) {
            self::WithdrawalApproved,
            self::WithdrawalRejected,
            self::WithdrawalPaid => 'wallet',
            self::RewardGranted => 'wallet',
            self::EpisodeUnlocked => 'player',
            self::NewEpisode => 'drama',
            self::System => 'home',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WithdrawalApproved => 'Withdrawal approved',
            self::WithdrawalRejected => 'Withdrawal rejected',
            self::WithdrawalPaid => 'Withdrawal paid',
            self::RewardGranted => 'Reward granted',
            self::EpisodeUnlocked => 'Episode unlocked',
            self::NewEpisode => 'New episode',
            self::System => 'System message',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
