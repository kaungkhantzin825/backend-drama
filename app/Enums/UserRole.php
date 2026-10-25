<?php

namespace App\Enums;

/**
 * Roles that can sign in to the admin panel.
 *
 * The admin panel is intentionally a separate guard from the mobile API:
 *  - `admin`    → full access (users, withdrawals, settings, ad waterfall).
 *  - `editor`   → content only (videos, categories, banners).
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    /**
     * Roles that may manage rewards, withdrawals and platform settings.
     */
    public function canManageRevenue(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::Admin->value => 'Administrator',
            self::Editor->value => 'Content Editor',
        ];
    }
}
