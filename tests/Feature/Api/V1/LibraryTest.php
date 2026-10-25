<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LedgerType;
use App\Models\Drama;
use App\Models\PointsLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Watch progress, history, my list — and the watch-time reward that pays for
 * playback.
 *
 * The invariant under test: the client reports what it played, and the server
 * alone decides how much of it is worth points. Watch time adds up across
 * videos, but replaying or inflating heartbeats must not mint extra points.
 */
class LibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rewards.watch_reward_interval_seconds' => 60,
            'rewards.watch_reward_points' => 5,
            'rewards.watch_max_seconds_per_ping' => 30,
            'rewards.daily_watch_reward_cap' => 1000,
        ]);
    }

    private function video(string $slug = 'library-drama', int $duration = 300): Drama
    {
        return Drama::factory()->create([
            'slug' => $slug,
            'status' => 'published',
            'duration_seconds' => $duration,
        ]);
    }

    private function heartbeat(User $user, Drama $video, int $position, ?int $watched = null): TestResponse
    {
        $payload = [
            'position_seconds' => $position,
            'duration_seconds' => $video->duration_seconds,
        ];

        if ($watched !== null) {
            $payload['watched_seconds'] = $watched;
        }

        return $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$video->id}/progress", $payload);
    }

    /**
     * Ten-second heartbeats for `$seconds` of real playback, the way the app
     * sends them.
     */
    private function watch(User $user, Drama $video, int $seconds, int $from = 0): int
    {
        $awarded = 0;

        for ($played = 10; $played <= $seconds; $played += 10) {
            $this->travel(10)->seconds();
            $awarded += (int) $this->heartbeat($user, $video, $from + $played, 10)->assertOk()->json('data.points_awarded');
        }

        return $awarded;
    }

    private function watchPoints(User $user): int
    {
        return (int) PointsLedger::query()
            ->where('user_id', $user->id)
            ->where('type', LedgerType::WatchReward->value)
            ->sum('delta');
    }

    // ─── Auth & access ────────────────────────────────────────────────────────

    public function test_progress_requires_authentication(): void
    {
        $video = $this->video();

        $this->postJson("/api/v1/dramas/{$video->id}/progress", [
            'position_seconds' => 30,
            'duration_seconds' => 300,
        ])->assertStatus(401);
    }

    public function test_an_unpublished_video_cannot_be_tracked(): void
    {
        $video = Drama::factory()->draft()->create(['duration_seconds' => 300]);
        $user = User::factory()->create();

        $this->heartbeat($user, $video, 30, 10)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── Watch-time rewards ───────────────────────────────────────────────────

    public function test_a_full_minute_of_playback_pays_out(): void
    {
        $user = User::factory()->create();

        $this->assertSame(5, $this->watch($user, $this->video(), 60));

        $this->assertSame(5, $user->fresh()->points_balance);
        $this->assertSame(5, $this->watchPoints($user));
    }

    public function test_a_minute_can_be_completed_across_two_videos(): void
    {
        $user = User::factory()->create();
        $first = $this->video('first');
        $second = $this->video('second');

        // 50 s of the first video earns nothing yet…
        $this->assertSame(0, $this->watch($user, $first, 50));

        // …and the meter carries on into the next one instead of resetting.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.watch_reward.seconds', 50)
            ->assertJsonPath('data.watch_reward.interval_seconds', 60);

        $this->assertSame(5, $this->watch($user, $second, 10));
        $this->assertSame(5, $user->fresh()->points_balance);
    }

    public function test_the_heartbeat_reports_the_meter_in_the_wallet(): void
    {
        $user = User::factory()->create();

        $this->watch($user, $this->video(), 70);

        $this->travel(10)->seconds();
        $this->heartbeat($user, $this->video('another'), 10, 10)
            ->assertOk()
            ->assertJsonPath('data.wallet.watch_reward.seconds', 20)
            ->assertJsonPath('data.wallet.watch_reward.capped', false);
    }

    public function test_a_replayed_heartbeat_does_not_pay_twice(): void
    {
        $user = User::factory()->create();
        $video = $this->video();

        $this->assertSame(5, $this->watch($user, $video, 60));

        // The same heartbeat again, in the same second, earns nothing.
        for ($i = 0; $i < 6; $i++) {
            $this->heartbeat($user, $video, 60, 10)->assertJsonPath('data.points_awarded', 0);
        }

        $this->assertSame(5, $user->fresh()->points_balance);
    }

    public function test_credited_watch_time_cannot_outrun_the_clock(): void
    {
        $user = User::factory()->create();
        $video = $this->video('long', 900);

        // Claiming ten minutes in one go is capped at one heartbeat's worth…
        $this->heartbeat($user, $video, 600, 600)
            ->assertOk()
            ->assertJsonPath('data.points_awarded', 0);

        // …and hammering the endpoint without time passing adds nothing more.
        for ($i = 0; $i < 10; $i++) {
            $this->heartbeat($user, $video, 600, 30)->assertJsonPath('data.points_awarded', 0);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertJsonPath('data.watch_reward.seconds', 30);

        // Once real time has passed, the next heartbeat completes the minute.
        $this->travel(30)->seconds();
        $this->heartbeat($user, $video, 630, 30)->assertJsonPath('data.points_awarded', 5);
    }

    public function test_an_older_client_is_metered_by_its_forward_progress(): void
    {
        $user = User::factory()->create();
        $video = $this->video();

        // No `watched_seconds`: the forward move through the video counts.
        for ($position = 20; $position <= 60; $position += 20) {
            $this->travel(20)->seconds();
            $this->heartbeat($user, $video, $position);
        }

        $this->assertSame(5, $user->fresh()->points_balance);
    }

    public function test_the_daily_cap_is_enforced(): void
    {
        config(['rewards.daily_watch_reward_cap' => 10]);

        $user = User::factory()->create();
        $video = $this->video('capped', 900);

        $this->assertSame(10, $this->watch($user, $video, 120));
        // Cap reached — further watching earns nothing today, and the meter
        // stops banking.
        $this->assertSame(0, $this->watch($user, $video, 90, 120));

        $this->assertSame(10, $user->fresh()->points_balance);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wallet')
            ->assertJsonPath('data.watch_reward.capped', true)
            ->assertJsonPath('data.watch_reward.seconds', 0);
    }

    public function test_progress_never_moves_backwards(): void
    {
        $user = User::factory()->create();
        $video = $this->video();

        $this->heartbeat($user, $video, 200, 10)->assertOk();
        $this->heartbeat($user, $video, 30, 10)
            ->assertOk()
            ->assertJsonPath('data.progress.position_seconds', 200);
    }

    public function test_finishing_a_video_marks_it_complete(): void
    {
        $user = User::factory()->create();

        $this->heartbeat($user, $this->video(), 300, 10)
            ->assertOk()
            ->assertJsonPath('data.progress.is_completed', true)
            ->assertJsonPath('data.progress.percent', 100);
    }

    // ─── Continue watching & history ──────────────────────────────────────────

    public function test_continue_watching_lists_unfinished_videos_newest_first(): void
    {
        $user = User::factory()->create();

        $this->heartbeat($user, $this->video('first'), 100, 10);
        $this->travel(1)->minutes();
        $this->heartbeat($user, $this->video('second'), 40, 10);

        $items = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/continue-watching')
            ->assertOk()
            ->json('data.items');

        $this->assertSame(['second', 'first'], array_column(array_column($items, 'drama'), 'slug'));
    }

    public function test_completed_videos_drop_out_of_continue_watching(): void
    {
        $user = User::factory()->create();

        $this->heartbeat($user, $this->video(), 300, 10);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/continue-watching')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_history_is_newest_first(): void
    {
        $user = User::factory()->create();

        $this->heartbeat($user, $this->video('older'), 30, 10);
        $this->travel(1)->minutes();
        $this->heartbeat($user, $this->video('newer'), 30, 10);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/history')
            ->assertOk()
            ->assertJsonPath('data.data.0.drama.slug', 'newer')
            ->assertJsonPath('meta.total', 2);
    }

    // ─── My list ──────────────────────────────────────────────────────────────

    public function test_a_drama_can_be_added_and_removed_from_the_list(): void
    {
        $user = User::factory()->create();
        $drama = $this->video();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dramas/{$drama->id}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'drama_id' => $drama->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dramas/{$drama->id}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_the_list_only_contains_published_titles(): void
    {
        $user = User::factory()->create();
        $published = $this->video();
        $draft = Drama::factory()->draft()->create(['slug' => 'draft-drama']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$published->id}/favorite")->assertOk();
        // A draft cannot be favourited in the first place.
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$draft->id}/favorite")->assertStatus(404);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.slug', 'library-drama');
    }

    public function test_drama_detail_reports_the_favorite_state(): void
    {
        $user = User::factory()->create();
        $drama = $this->video();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dramas/library-drama')
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/dramas/{$drama->id}/favorite")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dramas/library-drama')
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);
    }
}
