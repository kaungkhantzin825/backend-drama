<?php

namespace App\Enums;

/**
 * Lifecycle of a reported ad view.
 *
 * The client reports an impression; the server decides whether it is worth
 * points. Anything the fraud checks dislike lands in `Rejected` and pays
 * nothing, so a tampered client cannot mint points.
 */
enum AdEventStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
