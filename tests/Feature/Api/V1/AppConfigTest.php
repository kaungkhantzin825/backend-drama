<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppConfigService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Remote config: what the app reads on launch, and how admin edits reach it.
 */
class AppConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_public_and_returns_the_full_shape(): void
    {
        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_ads_enabled', true)
            ->assertJsonPath('data.ad_provider', 'admob')
            ->assertJsonPath('data.ad_interval_seconds', 60)
            ->assertJsonStructure([
                'data' => [
                    'ad_load_timeout_seconds',
                    'config_version',
                    'ad_units' => [
                        'android' => ['banner', 'interstitial', 'rewarded'],
                        'ios' => ['banner', 'interstitial', 'rewarded'],
                    ],
                ],
            ]);
    }

    public function test_the_seeded_interstitial_waterfall_keeps_its_order(): void
    {
        $this->seed(AppSettingSeeder::class);

        $this->getJson('/api/v1/app-config')
            ->assertJsonPath('data.ad_units.android.interstitial.0.unit_id', 'ca-app-pub-3940256099942544/1033173712')
            ->assertJsonPath('data.ad_units.android.interstitial.1.unit_id', 'ca-app-pub-3940256099942544/8691691433');
    }

    public function test_the_payload_is_served_from_cache(): void
    {
        $this->getJson('/api/v1/app-config')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/app-config')->assertOk();

        $settingQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'app_settings'));

        $this->assertCount(0, $settingQueries);
    }

    public function test_saving_a_setting_invalidates_the_cache(): void
    {
        $this->getJson('/api/v1/app-config')->assertJsonPath('data.is_ads_enabled', true);

        AppSetting::query()->updateOrCreate(['key' => 'is_ads_enabled'], ['value' => false, 'group' => 'ads']);

        $this->assertFalse(Cache::has(AppConfigService::CACHE_KEY));
        $this->getJson('/api/v1/app-config')->assertJsonPath('data.is_ads_enabled', false);
    }

    public function test_admins_can_update_the_config(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->putJson('/admin/api/app-config', [
                'ad_interval_seconds' => 45,
                'ad_units' => [
                    'android' => [
                        'interstitial' => [
                            ['unit_id' => 'ca-app-pub-1234567890123456/1111111111', 'label' => 'High floor'],
                            ['unit_id' => 'ca-app-pub-1234567890123456/2222222222', 'label' => 'Fallback'],
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.ad_interval_seconds', 45);

        $this->getJson('/api/v1/app-config')
            ->assertJsonPath('data.ad_interval_seconds', 45)
            ->assertJsonPath('data.ad_units.android.interstitial.0.label', 'High floor')
            // Untouched keys keep their defaults.
            ->assertJsonPath('data.is_ads_enabled', true);
    }

    public function test_malformed_unit_ids_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->putJson('/admin/api/app-config', [
                'ad_units' => ['android' => ['banner' => [['unit_id' => 'not-a-unit']]]],
            ])
            ->assertStatus(422);
    }

    public function test_editors_cannot_change_the_ad_config(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->putJson('/admin/api/app-config', ['is_ads_enabled' => false])
            ->assertStatus(403);
    }
}
