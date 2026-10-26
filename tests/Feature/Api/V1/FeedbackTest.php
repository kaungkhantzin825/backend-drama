<?php

namespace Tests\Feature\Api\V1;

use App\Mail\FeedbackReceived;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * In-app feedback: stored, emailed to support, readable in the admin panel.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function send(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0'])
            ->postJson('/api/v1/feedback', $payload + [
                'category' => 'payment',
                'message' => 'My KBZPay withdrawal is still pending.',
                'contact' => 'viewer@example.com',
            ]);
    }

    public function test_feedback_requires_a_signed_in_device(): void
    {
        $this->postJson('/api/v1/feedback', ['category' => 'bug', 'message' => 'Hello there'])
            ->assertStatus(401);
    }

    public function test_feedback_is_stored_and_emailed_to_support(): void
    {
        Mail::fake();
        config(['mail.feedback_to' => 'support@example.com']);

        $user = User::factory()->create();

        $this->send($user)
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $feedback = Feedback::query()->sole();
        $this->assertSame($user->id, $feedback->user_id);
        $this->assertSame('payment', $feedback->category);
        $this->assertSame('android', $feedback->platform);
        $this->assertSame('1.0.0', $feedback->app_version);
        $this->assertNotNull($feedback->fresh()->emailed_at);

        Mail::assertSent(FeedbackReceived::class, fn (FeedbackReceived $mail): bool => $mail->hasTo('support@example.com')
            && $mail->hasReplyTo('viewer@example.com'));
    }

    public function test_the_admin_address_overrides_the_environment(): void
    {
        Mail::fake();
        config(['mail.feedback_to' => 'env@example.com']);

        $this->actingAs(User::factory()->admin()->create(), 'admin')
            ->putJson('/admin/api/feedback/settings', ['email' => 'inbox@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'inbox@example.com');

        $this->send(User::factory()->create())->assertStatus(201);

        Mail::assertSent(FeedbackReceived::class, fn (FeedbackReceived $mail): bool => $mail->hasTo('inbox@example.com'));
    }

    public function test_feedback_is_validated(): void
    {
        $user = User::factory()->create();

        $this->send($user, ['category' => 'spam', 'message' => 'no'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['category', 'message']]]);

        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_the_categories_are_listed(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/feedback/categories')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', 'bug');
    }

    public function test_admins_read_the_inbox_and_mark_messages_read(): void
    {
        Mail::fake();

        $this->send(User::factory()->create())->assertStatus(201);
        $feedback = Feedback::query()->sole();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/feedback?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.data.0.category_label', 'Payment / withdrawal')
            ->assertJsonPath('data.data.0.is_read', false);

        $this->actingAs($admin, 'admin')->postJson("/admin/api/feedback/{$feedback->id}/read")->assertOk();

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/feedback/settings')
            ->assertJsonPath('data.unread', 0);
    }
}
