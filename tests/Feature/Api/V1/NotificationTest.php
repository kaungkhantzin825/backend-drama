<?php

namespace Tests\Feature\Api\V1;

use App\Enums\NotificationType;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\NotificationService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The in-app inbox.
 *
 * Two things are under test: that the read markers are correctly scoped to the
 * owner (a user must never be able to touch someone else's inbox), and that the
 * payout workflow actually writes a notification when an admin acts.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Reading the inbox ────────────────────────────────────────────────────

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_the_inbox_only_returns_the_callers_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        AppNotification::factory()->count(3)->for($user)->create();
        AppNotification::factory()->count(2)->for($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_the_inbox_is_newest_first(): void
    {
        $user = User::factory()->create();

        $older = AppNotification::factory()->for($user)->create([
            'title' => 'Older',
            'created_at' => now()->subDay(),
        ]);
        $newer = AppNotification::factory()->for($user)->create([
            'title' => 'Newer',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk();

        $this->assertSame($newer->id, $response->json('data.data.0.id'));
        $this->assertSame($older->id, $response->json('data.data.1.id'));
    }

    public function test_a_notification_carries_its_deep_link_route(): void
    {
        $user = User::factory()->create();

        AppNotification::factory()
            ->for($user)
            ->ofType(NotificationType::WithdrawalPaid)
            ->create(['title' => 'Withdrawal paid']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.data.0.type', 'withdrawal_paid')
            ->assertJsonPath('data.data.0.route', 'wallet')
            ->assertJsonPath('data.data.0.is_read', false);
    }

    public function test_the_unread_count_only_counts_unread_rows(): void
    {
        $user = User::factory()->create();

        AppNotification::factory()->count(2)->for($user)->create();
        AppNotification::factory()->for($user)->read()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    // ─── Read markers ─────────────────────────────────────────────────────────

    public function test_a_notification_can_be_marked_read(): void
    {
        $user = User::factory()->create();
        $notification = AppNotification::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_marking_read_twice_keeps_the_first_timestamp(): void
    {
        $user = User::factory()->create();
        $notification = AppNotification::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk();

        $firstReadAt = $notification->fresh()->read_at;

        $this->travel(5)->minutes();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk();

        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    public function test_a_user_cannot_mark_someone_elses_notification_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $notification = AppNotification::factory()->for($other)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_read_all_marks_only_the_callers_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        AppNotification::factory()->count(3)->for($user)->create();
        $untouched = AppNotification::factory()->for($other)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 3)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, AppNotification::query()->forUser($user)->unread()->count());
        $this->assertNull($untouched->fresh()->read_at);
    }

    // ─── Payout events write to the inbox ─────────────────────────────────────

    public function test_approving_a_withdrawal_notifies_the_owner(): void
    {
        $user = User::factory()->withPoints(6000)->create();
        $admin = User::factory()->admin()->create();
        $withdrawal = Withdrawal::factory()->for($user)->create(['points_amount' => 5000]);

        app(WithdrawalService::class)->approve($withdrawal, $admin);

        $notification = AppNotification::query()->forUser($user)->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::WithdrawalApproved, $notification->type);
        $this->assertSame($withdrawal->id, $notification->data['withdrawal_id']);
    }

    public function test_rejecting_a_withdrawal_notifies_the_owner_about_the_refund(): void
    {
        $user = User::factory()->withPoints(6000)->create();
        $admin = User::factory()->admin()->create();

        // Go through the real request flow so the balance is actually debited
        // before the rejection refunds it.
        $withdrawal = app(WithdrawalService::class)
            ->request($user, 'paypal', ['email' => 'payout@example.com']);

        $this->assertSame(0, $user->fresh()->points_balance);

        app(WithdrawalService::class)->reject($withdrawal, $admin, 'Suspicious activity');

        $notification = AppNotification::query()->forUser($user)->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::WithdrawalRejected, $notification->type);
        $this->assertStringContainsString('refunded', strtolower((string) $notification->body));
        $this->assertStringContainsString('$6.00', (string) $notification->body);
        $this->assertSame(6000, $user->fresh()->points_balance);
    }

    public function test_marking_paid_notifies_the_owner(): void
    {
        $user = User::factory()->withPoints(6000)->create();
        $admin = User::factory()->admin()->create();
        $withdrawal = Withdrawal::factory()->for($user)->approved()->create(['points_amount' => 5000]);

        app(WithdrawalService::class)->markPaid($withdrawal, $admin);

        $notification = AppNotification::query()->forUser($user)->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::WithdrawalPaid, $notification->type);
    }

    // ─── Push is queued, not sent inline ──────────────────────────────────────

    public function test_writing_a_notification_queues_a_push(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        app(NotificationService::class)->send(
            $user,
            NotificationType::System,
            'Hello',
            'This is a test.',
        );

        Queue::assertPushed(SendPushNotification::class);
        $this->assertDatabaseCount('app_notifications', 1);
    }
}
