<?php

namespace App\Enums;

/**
 * Why the suspicious-traffic detector flagged a subject.
 */
enum TrafficFlagReason: string
{
    case IpFlood = 'ip_flood';
    case ManyAccountsPerDevice = 'many_accounts_per_device';
    case ManyAccountsPerIp = 'many_accounts_per_ip';
    case EmulatorEarning = 'emulator_earning';
    case AdCapStreak = 'ad_cap_streak';

    public function label(): string
    {
        return match ($this) {
            self::IpFlood => 'Request flood from one IP',
            self::ManyAccountsPerDevice => 'Many accounts on one device',
            self::ManyAccountsPerIp => 'Many accounts on one IP',
            self::EmulatorEarning => 'Emulator earning rewards',
            self::AdCapStreak => 'Hits the daily ad cap every day',
        };
    }

    /** What a block acts on for this reason. */
    public function subjectType(): string
    {
        return match ($this) {
            self::IpFlood, self::ManyAccountsPerIp => 'ip',
            self::ManyAccountsPerDevice, self::EmulatorEarning => 'device',
            self::AdCapStreak => 'user',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
