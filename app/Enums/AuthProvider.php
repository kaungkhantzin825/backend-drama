<?php

namespace App\Enums;

/**
 * How a mobile user authenticates.
 *
 * Guests are real accounts too: they get their own points ledger, watch history
 * and can upgrade to a Google account later without losing anything.
 */
enum AuthProvider: string
{
    case Guest = 'guest';
    case Google = 'google';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
