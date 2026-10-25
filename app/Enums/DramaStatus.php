<?php

namespace App\Enums;

/**
 * Publication state of a drama.
 *
 * Only `Published` dramas are ever exposed to the mobile client. `Draft` is
 * what the admin panel writes while an editor is still preparing a video,
 * and `Archived` keeps a title visible in the admin panel (with its watch
 * history and reward data intact) after it has been pulled from the catalogue.
 */
enum DramaStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * True when the mobile client is allowed to see this drama.
     */
    public function isVisibleToMobile(): bool
    {
        return $this === self::Published;
    }

    /**
     * Human readable label for the admin panel.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
