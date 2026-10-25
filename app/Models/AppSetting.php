<?php

namespace App\Models;

use App\Services\AppConfigService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One remote-config entry.
 *
 * Any write drops the cached /app-config payload, so a change made from the
 * admin panel (or tinker, or a seeder) reaches the app on its next launch
 * instead of after the cache TTL.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property string $group
 * @property string|null $description
 */
class AppSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `json` (not `array`) so scalar values like `true` or `120`
            // round-trip as scalars.
            'value' => 'json',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(AppConfigService::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }
}
