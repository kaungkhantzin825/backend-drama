<?php

namespace App\Enums;

enum TrafficFlagStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs review',
            self::Dismissed => 'Dismissed',
            self::Blocked => 'Blocked',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
