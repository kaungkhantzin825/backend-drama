<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LedgerType;
use App\Models\LuckyWheelSpin;
use App\Models\PointsLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lucky wheel.
 *
 * The invariant under test: the server owns the prize. The client may render
 * the segments and animate to the returned index, but it cannot influence the
 * outcome, exceed the daily allowance, or get paid twice for one spin.
 */
class LuckyWheelTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function load(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/rewards/lucky-wheel');
    }

    private function spin(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/lucky-wheel/spin');
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_wheel_requires_authentication(): void
    {
        $this->getJson('/api/v1/rewards/lucky-wheel')->assertStatus(401);
        $this->postJson('/api/v1/rewards/lucky-wheel/spin')->assertStatus(401);
    }

    // ─── Payload ──────────────────────────────────────────────────────────────

    public function test_wheel_describes_segments_and_allowance(): void
    {
        config([
            'rewards.lucky_wheel.daily_spins' => 3,
            'rewards.lucky_wheel.prizes' => [
                ['points' => 5, 'weight' => 40],
                ['points' => 50, 'weight' => 10],
            ],
        ]);

        $response = $this->load($this->user())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.daily_limit', 3)
            ->assertJsonPath('data.spins_remaining', 3)
            ->assertJsonPath('data.spins_used_today', 0)
            ->assertJsonCount(2, 'data.segments');

        $this->assertSame(
            [['index' => 0, 'points' => 5], ['index' => 1, 'points' => 50]],
            $response->json('data.segments'),
        );
    }

    public function test_segment_points_come_from_config_not_the_client(): void
    {
        config([
            'rewards.lucky_wheel.prizes' => [
                ['points' => 7, 'weight' => 1],
            ],
        ]);

        $this->load($this->user())
            ->assertOk()
            ->assertJsonPath('data.segments.0.points', 7);
    }

    // ─── Spin ─────────────────────────────────────────────────────────────────

    public function test_spin_credits_the_prize_and_returns_the_winning_segment(): void
    {
        // A single-segment wheel makes the outcome deterministic.
        config(['rewards.lucky_wheel.prizes' => [['points' => 25, 'weight' => 1]]]);

        $user = $this->user();

        $this->spin($user)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.prize_points', 25)
            ->assertJsonPath('data.segment_index', 0)
            ->assertJsonPath('data.wallet.points_balance', 25);

        $this->assertSame(25, $user->refresh()->points_balance);

        $ledger = PointsLedger::query()->where('user_id', $user->id)->sole();

        $this->assertSame(LedgerType::LuckyWheel->value, $ledger->type->value);
        $this->assertSame(25, (int) $ledger->delta);
        $this->assertSame(25, (int) $ledger->balance_after);
        $this->assertSame('lucky_wheel_spin', $ledger->reference_type);
        $this->assertSame('wheel:'.$ledger->reference_id, $ledger->idempotency_key);
    }

    public function test_spin_always_returns_a_configured_segment(): void
    {
        config([
            'rewards.lucky_wheel.daily_spins' => 50,
            'rewards.lucky_wheel.prizes' => [
                ['points' => 5, 'weight' => 40],
                ['points' => 10, 'weight' => 25],
                ['points' => 100, 'weight' => 3],
            ],
        ]);

        $allowed = [5, 10, 100];
        $user = $this->user();

        for ($i = 0; $i < 20; $i++) {
            $response = $this->spin($user)->assertOk();

            $this->assertContains($response->json('data.prize_points'), $allowed);
            $this->assertContains($response->json('data.segment_index'), [0, 1, 2]);
        }

        $this->assertSame(20, LuckyWheelSpin::query()->where('user_id', $user->id)->count());
    }

    public function test_zero_point_segment_does_not_write_a_ledger_row(): void
    {
        config(['rewards.lucky_wheel.prizes' => [['points' => 0, 'weight' => 1]]]);

        $user = $this->user();

        $this->spin($user)
            ->assertOk()
            ->assertJsonPath('data.prize_points', 0)
            ->assertJsonPath('data.wallet.points_balance', 0);

        $this->assertSame(0, PointsLedger::query()->where('user_id', $user->id)->count());
        // The spin is still recorded, so it still consumes an allowance.
        $this->assertSame(1, LuckyWheelSpin::query()->where('user_id', $user->id)->count());
    }

    // ─── Daily limit ──────────────────────────────────────────────────────────

    public function test_daily_limit_is_enforced(): void
    {
        config([
            'rewards.lucky_wheel.daily_spins' => 2,
            'rewards.lucky_wheel.prizes' => [['points' => 5, 'weight' => 1]],
        ]);

        $user = $this->user();

        $this->spin($user)->assertOk()->assertJsonPath('data.spins_remaining', 1);
        $this->spin($user)->assertOk()->assertJsonPath('data.spins_remaining', 0);

        $this->spin($user)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.spins_remaining', 0);

        $this->assertSame(2, LuckyWheelSpin::query()->where('user_id', $user->id)->count());
        $this->assertSame(10, $user->refresh()->points_balance);
    }

    public function test_allowance_resets_the_next_day(): void
    {
        config([
            'rewards.lucky_wheel.daily_spins' => 1,
            'rewards.lucky_wheel.prizes' => [['points' => 5, 'weight' => 1]],
        ]);

        $user = $this->user();

        $this->spin($user)->assertOk();
        $this->spin($user)->assertStatus(422);

        $this->travel(1)->days();

        $this->load($user)
            ->assertOk()
            ->assertJsonPath('data.spins_used_today', 0)
            ->assertJsonPath('data.spins_remaining', 1);

        $this->spin($user)->assertOk();
    }

    public function test_allowance_is_per_user(): void
    {
        config([
            'rewards.lucky_wheel.daily_spins' => 1,
            'rewards.lucky_wheel.prizes' => [['points' => 5, 'weight' => 1]],
        ]);

        $first = $this->user();
        $second = $this->user();

        $this->spin($first)->assertOk();
        $this->spin($first)->assertStatus(422);

        // The second account is untouched by the first account's spins.
        $this->spin($second)->assertOk();
    }

    // ─── Integrity ────────────────────────────────────────────────────────────

    public function test_each_spin_pays_exactly_once(): void
    {
        config(['rewards.lucky_wheel.prizes' => [['points' => 20, 'weight' => 1]]]);

        $user = $this->user();

        $this->spin($user)->assertOk();
        $this->spin($user)->assertOk();

        // Two spins, two ledger rows, two idempotency keys — never merged.
        $keys = PointsLedger::query()
            ->where('user_id', $user->id)
            ->pluck('idempotency_key');

        $this->assertCount(2, $keys);
        $this->assertCount(2, $keys->unique());
        $this->assertSame(40, $user->refresh()->points_balance);
    }

    public function test_empty_prize_table_refuses_to_spin(): void
    {
        config(['rewards.lucky_wheel.prizes' => []]);

        $user = $this->user();

        $this->spin($user)->assertStatus(422);

        $this->assertSame(0, LuckyWheelSpin::query()->count());
        $this->assertSame(0, $user->refresh()->points_balance);
    }
}
