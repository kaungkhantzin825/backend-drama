<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LedgerType;
use App\Exceptions\InsufficientPointsException;
use App\Models\AdEvent;
use App\Models\Drama;
use App\Models\PointsLedger;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Earning and spending points.
 *
 * The theme running through this file is that the client is never trusted:
 * amounts come from config, replays are absorbed by idempotency keys, and the
 * balance can only move through an append-only ledger.
 */
class RewardsTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(WalletService::class);
    }

    // ─── Ledger mechanics ─────────────────────────────────────────────────────

    public function test_crediting_points_writes_a_ledger_row_and_moves_the_balance(): void
    {
        $user = User::factory()->create();

        $entry = $this->wallet->credit($user, LedgerType::AdminAdjust, 500, 'Goodwill');

        $this->assertSame(500, $entry->delta);
        $this->assertSame(500, $entry->balance_after);
        $this->assertSame(500, $user->fresh()->points_balance);
        $this->assertSame(500, $user->fresh()->total_points_earned);
    }

    public function test_debiting_points_reduces_the_balance_and_tracks_spend(): void
    {
        $user = User::factory()->withPoints(1000)->create();

        $this->wallet->debit($user, LedgerType::EpisodeUnlock, 300, 'Unlock');

        $fresh = $user->fresh();
        $this->assertSame(700, $fresh->points_balance);
        // Spending does not reduce the lifetime-earned counter.
        $this->assertSame(1000, $fresh->total_points_earned);
        $this->assertSame(300, $fresh->total_points_spent);
    }

    public function test_overspending_is_refused_and_leaves_the_balance_untouched(): void
    {
        $user = User::factory()->withPoints(100)->create();

        try {
            $this->wallet->debit($user, LedgerType::EpisodeUnlock, 500, 'Too expensive');
            $this->fail('Expected an InsufficientPointsException.');
        } catch (InsufficientPointsException $e) {
            $this->assertStringContainsString('points', strtolower($e->getMessage()));
        }

        $this->assertSame(100, $user->fresh()->points_balance);
        $this->assertSame(0, PointsLedger::query()->count());
    }

    public function test_a_replayed_idempotency_key_does_not_pay_twice(): void
    {
        $user = User::factory()->create();

        $first = $this->wallet->credit($user, LedgerType::AdReward, 10, 'Ad', 'ad:abc');
        $second = $this->wallet->credit($user, LedgerType::AdReward, 10, 'Ad', 'ad:abc');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(10, $user->fresh()->points_balance);
        $this->assertSame(1, PointsLedger::query()->count());
    }

    public function test_ledger_rows_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $entry = $this->wallet->credit($user, LedgerType::AdminAdjust, 10, 'Test');

        $this->expectException(RuntimeException::class);

        $entry->update(['delta' => 999999]);
    }

    public function test_ledger_rows_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $entry = $this->wallet->credit($user, LedgerType::AdminAdjust, 10, 'Test');

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    // ─── Wallet endpoint ──────────────────────────────────────────────────────

    public function test_the_wallet_endpoint_returns_the_balance_and_cash_value(): void
    {
        $user = User::factory()->withPoints(2500)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.points_balance', 2500)
            ->assertJsonPath('data.points_per_currency_unit', 1000)
            ->assertJsonPath('data.cash_value', 2.5)
            ->assertJsonPath('data.min_withdrawal_points', 5000)
            ->assertJsonPath('data.can_withdraw', false)
            ->assertJsonPath('data.check_in_available', true)
            ->assertJsonPath('data.daily_ad_limit', 20);
    }

    public function test_the_wallet_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/wallet')->assertStatus(401);
    }

    public function test_the_ledger_endpoint_lists_entries_newest_first(): void
    {
        $user = User::factory()->create();
        $this->wallet->credit($user, LedgerType::AdminAdjust, 100, 'First');
        $this->wallet->credit($user, LedgerType::AdReward, 10, 'Second');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet/ledger')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.description', 'Second')
            ->assertJsonPath('data.data.0.type', 'ad_reward')
            ->assertJsonPath('data.data.1.description', 'First');
    }

    // ─── Daily check-in ───────────────────────────────────────────────────────

    public function test_check_in_awards_the_configured_points(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/check-in')
            ->assertOk()
            ->assertJsonPath('data.points_awarded', 20)
            ->assertJsonPath('data.wallet.points_balance', 20)
            ->assertJsonPath('data.wallet.check_in_available', false);
    }

    public function test_check_in_twice_on_the_same_day_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/check-in')->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/check-in')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(20, $user->fresh()->points_balance);
    }

    public function test_check_in_is_available_again_the_next_day(): void
    {
        $user = User::factory()->create();

        $this->travelTo(now()->startOfDay()->addHours(9));
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rewards/check-in')->assertOk();

        $this->travelTo(now()->startOfDay()->addDay()->addHours(9));
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/check-in')
            ->assertOk()
            ->assertJsonPath('data.wallet.points_balance', 40);
    }

    // ─── Rewarded ads ─────────────────────────────────────────────────────────

    public function test_an_ad_impression_credits_points_and_records_the_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/events', [
                'placement' => 'rewarded_video',
                'idempotency_key' => 'impression-0001',
                'device_id' => 'device-abc',
            ])
            ->assertOk()
            ->assertJsonPath('data.points_awarded', 10)
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.wallet.points_balance', 10);

        $this->assertDatabaseHas('ad_events', [
            'user_id' => $user->id,
            'placement' => 'rewarded_video',
            'status' => 'verified',
        ]);
    }

    public function test_the_same_impression_key_is_never_paid_twice(): void
    {
        $user = User::factory()->create();
        $payload = ['placement' => 'rewarded_video', 'idempotency_key' => 'impression-dup'];

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ads/events', $payload)->assertOk();
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ads/events', $payload)->assertOk();

        $this->assertSame($first->json('data.ad_event_id'), $second->json('data.ad_event_id'));
        $this->assertSame(10, $user->fresh()->points_balance);
        $this->assertSame(1, AdEvent::query()->count());
    }

    public function test_the_daily_ad_cap_is_enforced(): void
    {
        config(['rewards.daily_ad_limit' => 3]);

        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/ads/events', [
                    'placement' => 'rewarded_video',
                    'idempotency_key' => 'impression-'.$i,
                ])
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/events', [
                'placement' => 'rewarded_video',
                'idempotency_key' => 'impression-over',
            ])
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertSame(30, $user->fresh()->points_balance);
    }

    public function test_the_ad_endpoint_validates_its_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/events', ['placement' => 'nonsense', 'idempotency_key' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.errors.placement.0', 'Unknown ad placement.')
            ->assertJsonPath('data.errors.idempotency_key.0', 'The idempotency key field must be at least 8 characters.');
    }

    // ─── Watch-to-earn meter ──────────────────────────────────────────────────

    public function test_the_wallet_reports_the_watch_meter(): void
    {
        config([
            'rewards.watch_reward_interval_seconds' => 60,
            'rewards.watch_reward_points' => 1,
            'rewards.daily_watch_reward_cap' => 100,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.watch_reward.seconds', 0)
            ->assertJsonPath('data.watch_reward.interval_seconds', 60)
            ->assertJsonPath('data.watch_reward.points_per_interval', 1)
            ->assertJsonPath('data.watch_reward.daily_cap', 100)
            ->assertJsonPath('data.watch_reward.capped', false);
    }

    public function test_the_unlock_endpoint_is_gone(): void
    {
        $user = User::factory()->withPoints(100)->create();
        $drama = Drama::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/episodes/{$drama->id}/unlock")
            ->assertStatus(404);

        $this->assertSame(100, $user->fresh()->points_balance);
    }
}
