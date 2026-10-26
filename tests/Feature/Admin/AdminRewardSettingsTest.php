<?php

namespace Tests\Feature\Admin;

use App\Models\Drama;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Reward settings" page: every earning and payout number is the admin's
 * to change, and a change applies to the very next request.
 */
class AdminRewardSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(array $values): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->putJson('/admin/api/reward-settings', $values)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ─── Access ───────────────────────────────────────────────────────────────

    public function test_admins_read_the_defaults_from_config(): void
    {
        config(['rewards.check_in_points' => 25]);

        $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/api/reward-settings')
            ->assertOk()
            ->assertJsonPath('data.check_in.points', 25)
            ->assertJsonPath('data.check_in.enabled', true)
            ->assertJsonPath('data.payout_methods.0.code', 'kbzpay')
            ->assertJsonStructure(['data' => [
                'watch_reward' => ['enabled', 'points', 'interval_seconds', 'daily_cap'],
                'ad_reward' => ['enabled', 'points', 'daily_limit'],
                'cash_out' => ['currency', 'points_per_unit', 'min_points', 'daily_limit'],
                'lucky_wheel' => ['enabled', 'daily_spins', 'prizes'],
            ]]);
    }

    public function test_editors_cannot_change_the_economy(): void
    {
        $this->actingAs(User::factory()->editor()->create(), 'admin')
            ->putJson('/admin/api/reward-settings', ['check_in' => ['enabled' => true, 'points' => 9999]])
            ->assertStatus(403);
    }

    // ─── Watch minutes ────────────────────────────────────────────────────────

    public function test_the_watch_interval_and_points_are_applied(): void
    {
        $this->save(['watch_reward' => [
            'enabled' => true, 'points' => 3, 'interval_seconds' => 30, 'daily_cap' => 100,
        ]]);

        $user = User::factory()->create();
        $video = Drama::factory()->create(['duration_seconds' => 300]);

        $awarded = 0;
        for ($i = 1; $i <= 3; $i++) {
            $this->travel(10)->seconds();
            $awarded += (int) $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/dramas/{$video->id}/progress", [
                    'position_seconds' => $i * 10, 'duration_seconds' => 300, 'watched_seconds' => 10,
                ])
                ->assertOk()
                ->assertJsonPath('data.wallet.watch_reward.interval_seconds', 30)
                ->json('data.points_awarded');
        }

        $this->assertSame(3, $awarded);
    }

    public function test_switching_watch_rewards_off_stops_payouts(): void
    {
        $this->save(['watch_reward' => [
            'enabled' => false, 'points' => 1, 'interval_seconds' => 10, 'daily_cap' => 100,
        ]]);

        $user = User::factory()->create();
        $video = Drama::factory()->create(['duration_seconds' => 300]);

        $this->travel(10)->seconds();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dramas/{$video->id}/progress", [
                'position_seconds' => 10, 'duration_seconds' => 300, 'watched_seconds' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.points_awarded', 0)
            ->assertJsonPath('data.wallet.watch_reward.enabled', false);
    }

    // ─── Check-in & ads ───────────────────────────────────────────────────────

    public function test_check_in_points_follow_the_setting(): void
    {
        $this->save(['check_in' => ['enabled' => true, 'points' => 75]]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/check-in')->assertOk();

        $this->assertSame(75, $user->fresh()->points_balance);
    }

    public function test_a_switched_off_check_in_is_refused_and_hidden(): void
    {
        $this->save(['check_in' => ['enabled' => false, 'points' => 20]]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/check-in')->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertJsonPath('data.check_in_enabled', false)
            ->assertJsonPath('data.check_in_available', false);

        $this->assertSame(0, $user->fresh()->points_balance);
    }

    public function test_ad_points_and_daily_limit_follow_the_setting(): void
    {
        $this->save(['ad_reward' => ['enabled' => true, 'points' => 15, 'daily_limit' => 1]]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/events', ['placement' => 'rewarded_video', 'idempotency_key' => 'first-ad-0001'])
            ->assertOk()
            ->assertJsonPath('data.points_awarded', 15);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/events', ['placement' => 'rewarded_video', 'idempotency_key' => 'second-ad-002'])
            ->assertStatus(429);

        $this->assertSame(15, $user->fresh()->points_balance);
    }

    // ─── Cash-out & payment methods ──────────────────────────────────────────

    public function test_currency_rate_and_minimum_shape_a_withdrawal(): void
    {
        $this->save(['cash_out' => [
            'currency' => 'mmk', 'points_per_unit' => 1, 'min_points' => 1000, 'daily_limit' => 1,
        ]]);

        $user = User::factory()->withPoints(5000)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertJsonPath('data.currency', 'MMK')
            ->assertJsonPath('data.cash_value', 5000)
            ->assertJsonPath('data.min_withdrawal_points', 1000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'kbzpay',
                'account_details' => ['account_name' => 'Aung Aung', 'account_number' => '09123456789'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.currency', 'MMK')
            ->assertJsonPath('data.amount', '5000');
    }

    public function test_only_switched_on_payment_methods_can_be_used(): void
    {
        $this->save(['payout_methods' => [
            ['code' => 'ayapay', 'name' => 'AYA Pay', 'account_label' => 'AYA Pay phone', 'account_hint' => '09…', 'input' => 'phone', 'enabled' => true],
            ['code' => 'paypal', 'name' => 'PayPal', 'account_label' => 'PayPal email', 'account_hint' => '', 'input' => 'email', 'enabled' => false],
        ]]);

        $user = User::factory()->withPoints(5000)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertJsonCount(1, 'data.payout_methods')
            ->assertJsonPath('data.payout_methods.0.code', 'ayapay')
            ->assertJsonPath('data.payout_methods.0.account_label', 'AYA Pay phone');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'paypal',
                'account_details' => ['account_name' => 'Aung Aung', 'account_number' => 'a@b.co'],
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'ayapay',
                'account_details' => ['account_name' => 'Aung Aung', 'account_number' => '09123456789'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.method_name', 'AYA Pay');
    }

    public function test_at_least_one_payment_method_must_stay_on(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->putJson('/admin/api/reward-settings', ['payout_methods' => [
                ['code' => 'kbzpay', 'name' => 'KBZPay', 'account_label' => 'Phone', 'input' => 'phone', 'enabled' => false],
            ]])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['payout_methods']]]);
    }

    // ─── Lucky wheel ──────────────────────────────────────────────────────────

    public function test_the_wheel_uses_the_admin_prize_table(): void
    {
        $this->save(['lucky_wheel' => [
            'enabled' => true,
            'daily_spins' => 1,
            'prizes' => [['points' => 7, 'weight' => 1], ['points' => 7, 'weight' => 3]],
        ]]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/rewards/lucky-wheel')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.daily_limit', 1)
            ->assertJsonCount(2, 'data.segments');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/lucky-wheel/spin')
            ->assertOk()
            ->assertJsonPath('data.prize_points', 7);

        // One spin a day.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/lucky-wheel/spin')->assertStatus(422);
    }

    public function test_a_closed_wheel_cannot_be_spun(): void
    {
        $this->save(['lucky_wheel' => [
            'enabled' => false,
            'daily_spins' => 3,
            'prizes' => [['points' => 5, 'weight' => 1], ['points' => 10, 'weight' => 1]],
        ]]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/rewards/lucky-wheel')
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/lucky-wheel/spin')->assertStatus(422);
    }

    public function test_a_wheel_needs_at_least_two_prizes(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->putJson('/admin/api/reward-settings', ['lucky_wheel' => [
                'enabled' => true, 'daily_spins' => 3, 'prizes' => [['points' => 5, 'weight' => 1]],
            ]])
            ->assertStatus(422);
    }
}
