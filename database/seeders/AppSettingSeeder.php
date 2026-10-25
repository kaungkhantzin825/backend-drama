<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\AppConfigService;
use Illuminate\Database\Seeder;

/**
 * Writes the default remote config (Google's test ad units) to the database.
 *
 * `firstOrCreate` so re-running the seeder never overwrites live unit ids an
 * admin has already entered.
 */
class AppSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AppConfigService::defaults() as $key => $setting) {
            AppSetting::query()->firstOrCreate(['key' => $key], $setting);
        }
    }
}
