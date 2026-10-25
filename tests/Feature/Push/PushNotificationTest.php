<?php

namespace Tests\Feature\Push;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\User;
use App\Services\Push\AccessTokenProvider;
use App\Services\Push\FirebaseMessaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FCM delivery.
 *
 * Google is never called: the token provider is swapped for a stub and the
 * HTTP client is faked. What is actually verified is the message shape, the
 * fan-out, and the pruning of dead device tokens.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function configureFcm(): void
    {
        config([
            'firebase.enabled' => true,
            'firebase.project_id' => 'demo-project',
            'firebase.credentials' => '/fake/service-account.json',
        ]);

        $this->app->instance(AccessTokenProvider::class, new class implements AccessTokenProvider
        {
            public function token(): ?string
            {
                return 'ya29.test-token';
            }
        });
    }

    private function messaging(): FirebaseMessaging
    {
        return $this->app->make(FirebaseMessaging::class);
    }

    // ─── Sending ──────────────────────────────────────────────────────────────

    public function test_nothing_is_sent_when_fcm_is_not_configured(): void
    {
        Http::fake();

        $result = $this->messaging()->send(['token-a'], 'Title', 'Body');

        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_one_request_is_made_per_device_token(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo/messages/1'])]);

        $result = $this->messaging()->send(['token-a', 'token-b'], 'Title', 'Body');

        $this->assertSame(2, $result['sent']);
        Http::assertSentCount(2);
    }

    public function test_duplicate_tokens_are_sent_once(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $result = $this->messaging()->send(['token-a', 'token-a', 'token-a'], 'Title', 'Body');

        $this->assertSame(1, $result['sent']);
        Http::assertSentCount(1);
    }

    public function test_the_message_payload_is_built_correctly(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $this->messaging()->send(
            ['token-a'],
            'Withdrawal paid',
            'Your payout was sent.',
            ['notification_id' => 7, 'route' => 'wallet', 'skipped' => null],
        );

        Http::assertSent(function (ClientRequest $request): bool {
            $message = $request['message'];

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
                && $request->hasHeader('Authorization', 'Bearer ya29.test-token')
                && $message['token'] === 'token-a'
                && $message['notification']['title'] === 'Withdrawal paid'
                && $message['notification']['body'] === 'Your payout was sent.'
                // Values are stringified for FCM, and nulls are dropped.
                && $message['data'] === ['notification_id' => '7', 'route' => 'wallet']
                && $message['android']['priority'] === 'high';
        });
    }

    public function test_a_dead_token_is_reported_as_invalid(): void
    {
        $this->configureFcm();

        Http::fake([
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'ok'], 200)
                ->push(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);

        $result = $this->messaging()->send(['token-live', 'token-dead'], 'Title', 'Body');

        $this->assertSame(1, $result['sent']);
        $this->assertSame(['token-dead'], $result['invalid']);
    }

    public function test_a_server_error_does_not_throw(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);

        $result = $this->messaging()->send(['token-a'], 'Title', 'Body');

        $this->assertSame(0, $result['sent']);
        $this->assertSame([], $result['invalid']);
    }

    // ─── The queued job ───────────────────────────────────────────────────────

    public function test_the_job_pushes_to_every_device_the_user_owns(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $user = User::factory()->create();
        Device::factory()->count(2)->for($user)->create();
        $notification = AppNotification::factory()->for($user)->create();

        (new SendPushNotification($notification->id))->handle($this->messaging());

        Http::assertSentCount(2);
        $this->assertNotNull($notification->fresh()->pushed_at);
    }

    public function test_the_job_prunes_tokens_google_rejects(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404)]);

        $user = User::factory()->create();
        $device = Device::factory()->for($user)->create(['fcm_token' => 'token-dead']);
        $notification = AppNotification::factory()->for($user)->create();

        (new SendPushNotification($notification->id))->handle($this->messaging());

        $this->assertNull($device->fresh()->fcm_token);
        // Nothing was delivered, so the row is not flagged as pushed.
        $this->assertNull($notification->fresh()->pushed_at);
    }

    public function test_the_job_does_not_push_the_same_notification_twice(): void
    {
        $this->configureFcm();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $user = User::factory()->create();
        Device::factory()->for($user)->create();
        $notification = AppNotification::factory()->for($user)->pushed()->create();

        (new SendPushNotification($notification->id))->handle($this->messaging());

        Http::assertNothingSent();
    }

    public function test_the_job_is_a_no_op_when_the_user_has_no_devices(): void
    {
        $this->configureFcm();
        Http::fake();

        $user = User::factory()->create();
        $notification = AppNotification::factory()->for($user)->create();

        (new SendPushNotification($notification->id))->handle($this->messaging());

        Http::assertNothingSent();
        $this->assertNull($notification->fresh()->pushed_at);
    }
}
