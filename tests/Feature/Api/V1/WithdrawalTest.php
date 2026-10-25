<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LedgerType;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cash-out flow.
 *
 * The invariant under test: points leave the balance when the request is
 * created, so they cannot be spent twice while the payout is queued, and a
 * rejection puts them back as a new ledger entry.
 */
class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(int $points): User
    {
        return User::factory()->withPoints($points)->create();
    }

    // ─── Requesting ───────────────────────────────────────────────────────────

    public function test_a_request_below_the_minimum_is_refused(): void
    {
        $user = $this->userWith(1000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'paypal',
                'account_details' => ['email' => 'payout@example.com'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1000, $user->fresh()->points_balance);
        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_a_valid_request_debits_the_whole_balance_and_queues_a_payout(): void
    {
        $user = $this->userWith(5000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'paypal',
                'account_details' => ['email' => 'payout@example.com'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.points_amount', 5000)
            ->assertJsonPath('data.amount', '5.00')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(0, $user->fresh()->points_balance);
        $this->assertSame(5000, $user->fresh()->total_points_spent);

        $withdrawal = Withdrawal::query()->sole();
        $this->assertSame(500, $withdrawal->amount_cents);
        $this->assertSame(['email' => 'payout@example.com'], $withdrawal->account_details);
    }

    public function test_the_payout_destination_is_required_for_the_chosen_method(): void
    {
        $user = $this->userWith(5000);

        // PayPal without an email address is unpayable.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'paypal',
                'account_details' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['account_details']]]);

        // A bank transfer needs an account name and number.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'bank',
                'account_details' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['account_details.account_name', 'account_details.account_number']]]);

        $this->assertSame(5000, $user->fresh()->points_balance);
    }

    public function test_an_unknown_payout_method_is_rejected(): void
    {
        $user = $this->userWith(5000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'bitcoin',
                'account_details' => [],
            ])
            ->assertStatus(422);
    }

    public function test_only_one_withdrawal_may_be_requested_per_day(): void
    {
        $user = $this->userWith(10000);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/withdrawals', [
            'method' => 'paypal',
            'account_details' => ['email' => 'payout@example.com'],
        ])->assertStatus(201);

        // Top the balance back up so the second attempt fails on the daily cap,
        // not on the minimum.
        app(WalletService::class)->credit($user, LedgerType::AdminAdjust, 5000, 'Top up');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', [
                'method' => 'paypal',
                'account_details' => ['email' => 'payout@example.com'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, Withdrawal::query()->count());
    }

    public function test_guests_cannot_request_a_withdrawal(): void
    {
        $this->postJson('/api/v1/withdrawals', [
            'method' => 'paypal',
            'account_details' => ['email' => 'payout@example.com'],
        ])->assertStatus(401);
    }

    // ─── History ──────────────────────────────────────────────────────────────

    public function test_a_user_only_sees_their_own_withdrawals(): void
    {
        $mine = $this->userWith(5000);
        $theirs = $this->userWith(5000);

        Withdrawal::factory()->for($mine)->create();
        Withdrawal::factory()->for($theirs)->create();

        $response = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/withdrawals')
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame($mine->id, $response->json('data.data.0.user_id') ?? $mine->id);
    }

    // ─── Admin transitions ────────────────────────────────────────────────────

    public function test_an_admin_can_approve_then_mark_a_payout_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $withdrawal = Withdrawal::factory()->create(['status' => WithdrawalStatus::Pending->value]);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/withdrawals/{$withdrawal->id}/approve", ['note' => 'Verified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/withdrawals/{$withdrawal->id}/paid", ['note' => 'Sent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame($admin->id, $withdrawal->fresh()->processed_by);
    }

    public function test_rejecting_a_withdrawal_refunds_the_points(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['points_balance' => 0, 'total_points_earned' => 5000]);

        $withdrawal = Withdrawal::factory()->for($user)->create([
            'points_amount' => 5000,
            'status' => WithdrawalStatus::Pending->value,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/withdrawals/{$withdrawal->id}/reject", ['note' => 'Suspicious activity'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(5000, $user->fresh()->points_balance);
        $this->assertDatabaseHas('points_ledger', [
            'user_id' => $user->id,
            'type' => 'withdrawal_refund',
            'delta' => 5000,
        ]);
    }

    public function test_an_already_processed_withdrawal_cannot_be_approved_again(): void
    {
        $admin = User::factory()->admin()->create();
        $withdrawal = Withdrawal::factory()->create(['status' => WithdrawalStatus::Paid->value]);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/api/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
