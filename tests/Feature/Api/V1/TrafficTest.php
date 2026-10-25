<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TrafficFlagReason;
use App\Enums\TrafficFlagStatus;
use App\Models\Drama;
use App\Models\TrafficFlag;
use App\Models\User;
use App\Services\TrafficGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Traffic monitoring: what gets recorded, what gets flagged, what gets blocked.
 */
class TrafficTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function appHeaders(string $deviceId = 'dl-android-test-device'): array
    {
        return [
            'X-App-Platform' => 'android',
            'X-App-Version' => '1.2.0',
            'X-Device-Id' => $deviceId,
        ];
    }

    // ─── Recording ───────────────────────────────────────────────────────────

    public function test_api_requests_are_recorded_per_hour_and_route(): void
    {
        $this->withHeaders($this->appHeaders())->getJson('/api/v1/categories')->assertOk();
        $this->withHeaders($this->appHeaders())->getJson('/api/v1/categories')->assertOk();

        $row = DB::table('api_traffic_hourly')->where('route', 'api.v1.categories.index')->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->requests);
        $this->assertSame(2, (int) $row->status_class);
        $this->assertSame('android', $row->platform);
        $this->assertSame('1.2.0', $row->app_version);
    }

    public function test_authenticated_requests_count_as_user_activity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->withHeaders($this->appHeaders())->getJson('/api/v1/wallet')->assertOk();
        $this->actingAs($user, 'sanctum')->withHeaders($this->appHeaders())
            ->postJson('/api/v1/activity/session')->assertOk();

        $row = DB::table('user_daily_activity')->where('user_id', $user->id)->first();

        $this->assertSame(2, (int) $row->requests);
        $this->assertSame(1, (int) $row->sessions);
        $this->assertSame('android', $row->platform);
        $this->assertNotNull($user->fresh()->last_ip);
    }

    public function test_ad_events_are_folded_into_waterfall_buckets(): void
    {
        $user = User::factory()->create();
        $unit = 'ca-app-pub-3940256099942544/1033173712';

        $this->actingAs($user, 'sanctum')
            ->withHeaders($this->appHeaders() + ['X-AB-Group' => 'B'])
            ->postJson('/api/v1/ads/traffic', ['events' => [
                ['event' => 'request', 'format' => 'interstitial', 'placement' => 'preload', 'unit_id' => $unit, 'tier' => 1],
                ['event' => 'request', 'format' => 'interstitial', 'placement' => 'preload', 'unit_id' => $unit, 'tier' => 1],
                ['event' => 'no_fill', 'format' => 'interstitial', 'placement' => 'preload', 'unit_id' => $unit, 'tier' => 1],
                ['event' => 'fill', 'format' => 'interstitial', 'placement' => 'preload', 'unit_id' => $unit, 'tier' => 1],
                ['event' => 'impression', 'format' => 'interstitial', 'placement' => 'episode_break', 'unit_id' => $unit, 'tier' => 1],
                ['event' => 'paid', 'format' => 'interstitial', 'placement' => 'episode_break', 'unit_id' => $unit, 'tier' => 1, 'value_micros' => 4200],
            ]])
            ->assertOk()
            ->assertJsonPath('data.accepted', 6);

        $preload = DB::table('ad_traffic_daily')->where('placement', 'preload')->first();
        $this->assertSame(2, (int) $preload->requests);
        $this->assertSame(1, (int) $preload->fills);
        $this->assertSame(1, (int) $preload->no_fills);
        $this->assertSame('B', $preload->ab_group);

        $shown = DB::table('ad_traffic_daily')->where('placement', 'episode_break')->first();
        $this->assertSame(1, (int) $shown->impressions);
        $this->assertSame(4200, (int) $shown->revenue_micros);

        $this->assertSame(1, (int) DB::table('user_daily_activity')->where('user_id', $user->id)->value('ad_impressions'));
    }

    public function test_unknown_ad_events_are_rejected(): void
    {
        $this->postJson('/api/v1/ads/traffic', ['events' => [
            ['event' => 'mint_points', 'format' => 'interstitial'],
        ]])->assertStatus(422);
    }

    public function test_playback_progress_counts_as_watch_time(): void
    {
        $drama = Drama::factory()->create(['status' => 'published', 'duration_seconds' => 120]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$drama->id}/progress", [
            'position_seconds' => 10, 'duration_seconds' => 120, 'watched_seconds' => 10,
        ])->assertOk();

        $this->assertSame(10, (int) DB::table('user_daily_activity')->where('user_id', $user->id)->value('watch_seconds'));
    }

    // ─── Detection & blocking ────────────────────────────────────────────────

    public function test_an_ip_flood_is_flagged_once(): void
    {
        config(['traffic.ip_requests_per_minute' => 3]);

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/health');
        }

        $flag = TrafficFlag::query()->where('reason', TrafficFlagReason::IpFlood->value)->first();

        $this->assertNotNull($flag);
        $this->assertSame('ip', $flag->subject_type);
        $this->assertSame(1, $flag->hits);
        $this->assertSame(TrafficFlagStatus::Open, $flag->status);
    }

    public function test_auto_block_cuts_off_a_flooding_ip(): void
    {
        config(['traffic.ip_requests_per_minute' => 2, 'traffic.auto_block' => true]);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health'); // crosses the limit → flagged and blocked

        $this->getJson('/api/v1/categories')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_blocked_device_is_turned_away(): void
    {
        $flag = app(TrafficGuard::class)->flag(TrafficFlagReason::ManyAccountsPerDevice, 'dl-bad-device', 'medium');
        app(TrafficGuard::class)->block($flag, null);

        $this->withHeaders($this->appHeaders('dl-bad-device'))->getJson('/api/v1/categories')->assertStatus(403);
        $this->withHeaders($this->appHeaders('dl-good-device'))->getJson('/api/v1/categories')->assertOk();
    }

    public function test_a_banned_account_cannot_use_a_fresh_token(): void
    {
        $user = User::factory()->banned()->create();
        $token = $user->createToken('guest')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended.');
    }

    public function test_the_scan_flags_account_farms_on_one_device(): void
    {
        config(['traffic.max_accounts_per_device' => 2]);
        User::factory()->count(3)->create(['device_id' => 'dl-farm-device']);
        User::factory()->create(['device_id' => 'dl-normal-device']);

        $found = app(TrafficGuard::class)->scan();

        $this->assertSame(1, $found[TrafficFlagReason::ManyAccountsPerDevice->value]);
        $this->assertDatabaseHas('traffic_flags', ['subject' => 'dl-farm-device', 'reason' => 'many_accounts_per_device']);
    }

    public function test_a_dismissed_flag_is_not_reopened_by_a_repeat(): void
    {
        $guard = app(TrafficGuard::class);
        $admin = User::factory()->admin()->create();

        $flag = $guard->flag(TrafficFlagReason::ManyAccountsPerIp, '10.0.0.1', 'medium');
        $guard->dismiss($flag, $admin);

        $again = $guard->flag(TrafficFlagReason::ManyAccountsPerIp, '10.0.0.1', 'medium');

        $this->assertSame(TrafficFlagStatus::Dismissed, $again->status);
        $this->assertSame(2, $again->hits);
    }

    // ─── Release policy ──────────────────────────────────────────────────────

    public function test_maintenance_mode_answers_503_but_keeps_the_config_reachable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->putJson('/admin/api/app-config', [
            'maintenance' => ['enabled' => true, 'message' => 'Back at 5pm.'],
        ])->assertOk();

        $this->getJson('/api/v1/categories')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Back at 5pm.');

        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true);
    }

    public function test_an_outdated_app_gets_426_only_when_it_sends_its_version(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->putJson('/admin/api/app-config', [
            'app_version' => ['min_supported' => '2.0.0', 'latest' => '2.1.0'],
        ])->assertOk();

        $this->withHeaders(['X-App-Version' => '1.9.9'])->getJson('/api/v1/categories')
            ->assertStatus(426)
            ->assertJsonPath('data.min_supported', '2.0.0');

        $this->withHeaders(['X-App-Version' => '2.0.0'])->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
    }

    public function test_min_version_cannot_exceed_latest(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')->putJson('/admin/api/app-config', [
            'app_version' => ['min_supported' => '3.0.0', 'latest' => '2.0.0'],
        ])->assertStatus(422);
    }

    // ─── A/B experiment ──────────────────────────────────────────────────────

    public function test_devices_get_a_stable_variant_with_its_overrides(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'admin')->putJson('/admin/api/app-config', [
            'ad_interval_seconds' => 120,
            'ad_experiment' => [
                'enabled' => true,
                'name' => 'gap_test',
                'variants' => [
                    ['key' => 'A', 'weight' => 50, 'overrides' => []],
                    ['key' => 'B', 'weight' => 50, 'overrides' => ['ad_interval_seconds' => 45]],
                ],
            ],
        ])->assertOk();

        $seen = [];

        // Enough devices that both variants are all but certain to appear
        // (while staying under the endpoint's 60/min throttle).
        for ($i = 0; $i < 30; $i++) {
            $data = $this->withHeaders(['X-Device-Id' => "device-{$i}"])->getJson('/api/v1/app-config')->json('data');
            $seen[$data['ab_group']] = $data['ad_interval_seconds'];

            // Same device, same answer.
            if ($i < 5) {
                $this->assertSame(
                    $data['ab_group'],
                    $this->withHeaders(['X-Device-Id' => "device-{$i}"])->getJson('/api/v1/app-config')->json('data.ab_group'),
                );
            }
        }

        $this->assertSame(['A' => 120, 'B' => 45], array_intersect_key($seen, ['A' => 1, 'B' => 1]));

        // No device id → no experiment.
        $this->withHeaders(['X-Device-Id' => ''])->getJson('/api/v1/app-config')->assertJsonPath('data.ab_group', null);
    }

    // ─── Free-to-watch ───────────────────────────────────────────────────────

    public function test_every_video_streams_and_a_minute_earns_one_point(): void
    {
        config(['rewards.watch_reward_points' => 1, 'rewards.watch_reward_interval_seconds' => 60]);

        $drama = Drama::factory()->create([
            'slug' => 'free-drama',
            'status' => 'published',
            'video_url' => 'https://cdn.test/free.m3u8',
            'duration_seconds' => 120,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dramas/free-drama')
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://cdn.test/free.m3u8');

        // One full minute of 10-second heartbeats → exactly one point.
        $awarded = 0;
        for ($position = 10; $position <= 60; $position += 10) {
            $this->travel(10)->seconds();
            $awarded += (int) $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$drama->id}/progress", [
                'position_seconds' => $position, 'duration_seconds' => 120, 'watched_seconds' => 10,
            ])->assertOk()->json('data.points_awarded');
        }

        $this->assertSame(1, $awarded);
        $this->assertSame(1, (int) $user->fresh()->points_balance);
    }

    public function test_the_payload_carries_ad_rules(): void
    {
        $this->getJson('/api/v1/app-config')
            ->assertJsonPath('data.ad_rules.interstitial.first_after_episodes', 1)
            ->assertJsonPath('data.ad_rules.app_open.enabled', true)
            ->assertJsonPath('data.app_version.min_supported', '1.0.0');
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    public function test_admins_can_read_every_report_and_export_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withHeaders($this->appHeaders())->getJson('/api/v1/categories');

        foreach (['activity', 'api', 'ads', 'ab'] as $report) {
            $this->actingAs($admin, 'admin')->getJson("/admin/api/traffic/{$report}?days=7")
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $csv = $this->actingAs($admin, 'admin')->get('/admin/api/traffic/export?report=api&days=7');
        $csv->assertOk();
        $this->assertStringContainsString('date,requests', $csv->streamedContent());
    }

    public function test_admins_can_block_a_user_flag_which_bans_the_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $flag = app(TrafficGuard::class)->flag(TrafficFlagReason::AdCapStreak, (string) $user->id, 'medium', [], $user->id);

        $this->actingAs($admin, 'admin')->postJson("/admin/api/traffic/flags/{$flag->id}/block")->assertOk();
        $this->assertNotNull($user->fresh()->banned_at);

        $this->actingAs($admin, 'admin')->postJson("/admin/api/traffic/flags/{$flag->id}/unblock")->assertOk();
        $this->assertNull($user->fresh()->banned_at);
    }

    public function test_editors_cannot_see_traffic(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor, 'admin')->getJson('/admin/api/traffic/activity')->assertStatus(403);
        $this->actingAs($editor, 'admin')->getJson('/admin/api/traffic/flags')->assertStatus(403);
    }
}
