<?php

namespace App\Enums;

/**
 * Mobile platform a device is registered from.
 */
enum Platform: string
{
    case Android = 'android';
    case IOS = 'ios';
    case Web = 'web';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
