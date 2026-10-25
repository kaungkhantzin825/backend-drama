<?php

namespace Tests\Feature\Admin;

use App\Enums\LedgerType;
use App\Enums\WithdrawalStatus;
use App\Models\AdEvent;
use App\Models\Drama;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin back-office for the rewards economy.
 *
 * Two concerns dominate: only staff may read the economy, and banning an
 * account must actually cut off its mobile access.
 */
class AdminRewardsTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(WalletService::class);
    }

    // ─── Authorisation ────────────────────────────────────────────────────────

    public function test_guests_cannot_read_the_economy(): void
    {
        $this->getJson('/admin/api/stats')->assertStatus(401);
        $this->getJson('/admin/api/rewards/ledger')->assertStatus(401);
        $this->getJson('/admin/api/users')->assertStatus(401);
        $this->getJson('/admin/api/withdrawals')->assertStatus(401);
        $this->getJson('/admin/api/ad-events')->assertStatus(401);
    }

    public function test_mobile_users_cannot_read_the_economy(): void
    {
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'web')->getJson('/admin/api/stats')->assertStatus(401);
    }

    public function test_editors_cannot_process_payouts(): void
    {
        $editor = User::factory()->editor()->create();
        $withdrawal = Withdrawal::factory()->create();

        // Payout actions are admin-only; content is the editor's domain.
        $this->actingAs($editor, 'admin')
            ->postJson("/admin/api/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(403);
    }

    public function test_editors_cannot_read_the_economy(): void
    {
        $editor = User::factory()->editor()->create();

        // The points economy is admin-only; editors own content, not money.
        $this->actingAs($editor, 'admin')
            ->getJson('/admin/api/rewards/ledger')
            ->assertStatus(403);

        $this->actingAs($editor, 'admin')
            ->getJson('/admin/api/rewards/stats')
            ->assertStatus(403);
    }

    // ─── Stats ────────────────────────────────────────────────────────────────

    public function test_the_stats_endpoint_reports_users_content_and_economy(): void
    {
        $admin = User::factory()->admin()->create();
        Drama::factory()->create();
        $user = User::factory()->create();
        $this->wallet->credit($user, LedgerType::AdminAdjust, 750, 'Seed');

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/stats')
            ->assertOk()
            ->assertJsonPath('data.users.total', 1) // staff accounts are excluded
            ->assertJsonPath('data.content.dramas', 1)
            ->assertJsonMissingPath('data.content.episodes')
            ->assertJsonPath('data.economy.points_outstanding', 750)
            ->assertJsonPath('data.economy.earned_today', 750)
            ->assertJsonPath('data.economy.ledger_entries', 1)
            ->assertJsonPath('data.withdrawals.pending', 0);
    }

    // ─── Ledger ───────────────────────────────────────────────────────────────

    public function test_the_ledger_can_be_filtered_by_type(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->wallet->credit($user, LedgerType::AdminAdjust, 100, 'Adjustment');
        $this->wallet->credit($user, LedgerType::AdReward, 10, 'Ad');

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/rewards/ledger?type=ad_reward')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.type', 'ad_reward')
            ->assertJsonPath('data.data.0.user.id', $user->id);
    }

    public function test_the_rewards_stats_endpoint_breaks_the_economy_down_by_type(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->wallet->credit($user, LedgerType::CheckIn, 20, 'Check-in');

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/rewards/stats')
            ->assertOk()
            ->assertJsonPath('data.points_outstanding', 20)
            ->assertJsonPath('data.ads_today', 0);

        $byType = collect($response->json('data.by_type'))->keyBy('type');

        $this->assertSame(20, $byType['check_in']['net']);
        $this->assertSame('Daily check-in', $byType['check_in']['label']);
    }

    // ─── Users ────────────────────────────────────────────────────────────────

    public function test_users_can_be_searched_by_name(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->guest()->create(['name' => 'Ada Lovelace']);
        User::factory()->guest()->create(['name' => 'Grace Hopper']);

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/users?q=Ada')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Ada Lovelace');
    }

    public function test_banning_a_user_revokes_their_mobile_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->guest()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/users/{$user->id}/ban", ['reason' => 'Ad fraud'])
            ->assertOk()
            ->assertJsonPath('data.is_banned', true);

        $this->assertNotNull($user->fresh()->banned_at);
        $this->assertSame(0, $user->tokens()->count());

        // The revoked token no longer opens an authenticated endpoint.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/wallet')
            ->assertStatus(401);
    }

    public function test_a_ban_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->guest()->create();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/users/{$user->id}/ban", [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['reason']]]);
    }

    public function test_a_user_can_be_unbanned(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->banned()->create();

        $this->actingAs($admin, 'admin')
            ->deleteJson("/admin/api/users/{$user->id}/ban")
            ->assertOk()
            ->assertJsonPath('data.is_banned', false);

        $this->assertNull($user->fresh()->banned_at);
    }

    public function test_the_user_detail_endpoint_returns_counts_and_recent_ledger(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->wallet->credit($user, LedgerType::AdminAdjust, 500, 'Seed');

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.points_balance', 500)
            ->assertJsonPath('data.counts.ad_events', 0)
            ->assertJsonCount(1, 'data.recent_ledger')
            ->assertJsonPath('data.recent_ledger.0.description', 'Seed');
    }

    // ─── Withdrawal queue ─────────────────────────────────────────────────────

    public function test_the_withdrawal_queue_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        Withdrawal::factory()->create(['status' => WithdrawalStatus::Pending->value]);
        Withdrawal::factory()->paid()->create();

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/withdrawals?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'pending');
    }

    // ─── Ad events ────────────────────────────────────────────────────────────

    public function test_ad_events_are_listed_with_their_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->wallet->claimAdReward($user, 'rewarded_video', 'impression-admin-1', 'device-1', '127.0.0.1');

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/ad-events')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.placement', 'rewarded_video')
            ->assertJsonPath('data.data.0.status', 'verified');

        $this->assertSame(1, AdEvent::query()->count());
    }
}
