<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the mobile authentication endpoints.
 *
 * @see \App\Http\Controllers\Api\V1\AuthController
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A client id configured for the token-verification tests.
     */
    private const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';

    /**
     * Google refuses to mint tokens for a fake client id, so the JWKS
     * endpoint is stubbed and a token is signed with a local key. The
     * verification logic (signature, audience, issuer, expiry) is still
     * genuinely exercised.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.client_id_ios' => null,
        ]);

        // AuthManager caches guard instances (RequestGuard) for the lifetime
        // of the application instance. In the test framework the app persists
        // across multiple getJson/postJson calls within a single test, so the
        // sanctum guard resolves the user on the first request and then keeps
        // returning the cached user — even after the token is deleted. Calling
        // forgetGuards() here ensures each request re-resolves the guard.
        Auth::forgetGuards();
    }

    // ─── Guest login ──────────────────────────────────────────────────────────

    public function test_guest_login_creates_an_account_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/guest', [
            'device_id' => 'device-abc-123-xyz',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'auth_provider', 'points_balance'],
                    'token',
                    'token_type',
                ],
            ])
            // The envelope contract: success is always boolean, message a string.
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.auth_provider', 'guest')
            ->assertJsonPath('data.user.email', null)
            ->assertJsonPath('data.user.points_balance', 0);

        $this->assertDatabaseHas('users', [
            'device_id' => 'device-abc-123-xyz',
            'google_id' => null,
        ]);

        // The device row must exist with the metadata we were sent.
        $this->assertDatabaseHas('devices', [
            'device_id' => 'device-abc-123-xyz',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ]);
    }

    public function test_guest_login_returns_a_usable_bearer_token(): void
    {
        $token = $this->postJson('/api/v1/auth/guest', [
            'device_id' => 'device-abc-123-xyz',
        ])->json('data.token');

        $this->assertNotEmpty($token);

        // The token must authenticate a real request.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.auth_provider', 'guest');
    }

    public function test_guest_login_is_idempotent_for_the_same_device(): void
    {
        $first = $this->postJson('/api/v1/auth/guest', [
            'device_id' => 'device-repeat-me',
        ])->assertOk();

        $second = $this->postJson('/api/v1/auth/guest', [
            'device_id' => 'device-repeat-me',
        ])->assertOk();

        // Same account is returned — the user's history must survive a reinstall.
        $this->assertSame(
            $first->json('data.user.id'),
            $second->json('data.user.id'),
        );

        $this->assertSame(1, User::query()->where('device_id', 'device-repeat-me')->count());
    }

    public function test_guest_login_requires_a_device_id(): void
    {
        $response = $this->postJson('/api/v1/auth/guest', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data' => ['errors' => ['device_id']]]);
    }

    public function test_guest_login_rejects_a_too_short_device_id(): void
    {
        $this->postJson('/api/v1/auth/guest', ['device_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.device_id.0', 'The device id is not valid.');
    }

    public function test_guest_login_rejects_an_unsupported_platform(): void
    {
        $this->postJson('/api/v1/auth/guest', [
            'device_id' => 'device-abc-123-xyz',
            'platform' => 'windows-phone',
        ])->assertStatus(422);
    }

    // ─── Google login ─────────────────────────────────────────────────────────

    public function test_google_login_creates_an_account_for_a_valid_token(): void
    {
        $idToken = $this->makeGoogleIdToken([
            'aud' => self::CLIENT_ID,
            'email' => 'player@dramaloop.test',
            'name' => 'Test Player',
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => $idToken,
            'device_id' => 'device-google-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.auth_provider', 'google')
            ->assertJsonPath('data.user.email', 'player@dramaloop.test')
            ->assertJsonPath('data.user.name', 'Test Player');

        $this->assertDatabaseHas('users', [
            'email' => 'player@dramaloop.test',
            'google_id' => 'google-sub-123',
        ]);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'device-google-001',
        ]);
    }

    public function test_google_login_is_idempotent_for_the_same_google_account(): void
    {
        $idToken = $this->makeGoogleIdToken([
            'aud' => self::CLIENT_ID,
            'email' => 'player@dramaloop.test',
            'name' => 'Test Player',
        ]);

        $first = $this->postJson('/api/v1/auth/google', ['id_token' => $idToken])->assertOk();
        $second = $this->postJson('/api/v1/auth/google', ['id_token' => $idToken])->assertOk();

        $this->assertSame($first->json('data.user.id'), $second->json('data.user.id'));
        $this->assertSame(1, User::query()->where('email', 'player@dramaloop.test')->count());
    }

    public function test_google_login_rejects_a_token_signed_for_another_app(): void
    {
        $idToken = $this->makeGoogleIdToken([
            'aud' => 'some-other-app.apps.googleusercontent.com',
            'email' => 'player@dramaloop.test',
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => $idToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The Google ID token could not be verified.');

        $this->assertSame(0, User::query()->whereNotNull('google_id')->count());
    }

    public function test_google_login_rejects_a_token_from_an_unknown_issuer(): void
    {
        $idToken = $this->makeGoogleIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://evil.example.com',
            'email' => 'player@dramaloop.test',
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => $idToken])
            ->assertStatus(401);

        $this->assertSame(0, User::query()->whereNotNull('google_id')->count());
    }

    public function test_google_login_rejects_a_token_with_a_tampered_signature(): void
    {
        $idToken = $this->makeGoogleIdToken([
            'aud' => self::CLIENT_ID,
            'email' => 'player@dramaloop.test',
        ]);

        // Flip a character in the signature segment.
        $parts = explode('.', $idToken);
        $parts[2] = strrev($parts[2]).'xyz';

        $this->postJson('/api/v1/auth/google', ['id_token' => implode('.', $parts)])
            ->assertStatus(401);

        $this->assertSame(0, User::query()->whereNotNull('google_id')->count());
    }

    public function test_google_login_requires_an_id_token(): void
    {
        $this->postJson('/api/v1/auth/google', [])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.id_token.0', 'A Google ID token is required.');
    }

    public function test_google_login_requires_a_minimum_length_token(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => 'short'])
            ->assertStatus(422);
    }

    public function test_google_login_fails_cleanly_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_id' => null]);
        config(['services.google.client_id_ios' => null]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => str_repeat('fake-google-id-token.', 8),
        ])
            // A configuration problem is a server fault, not a client error,
            // and it must still use the unified envelope.
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    // ─── Me ───────────────────────────────────────────────────────────────────

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->guest()->create(['points_balance' => 420]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.points_balance', 420);
    }

    public function test_me_lists_the_users_devices(): void
    {
        $user = User::factory()->guest()->create();

        Device::factory()->forUser($user)->create([
            'device_id' => 'my-device-01',
            'platform' => 'android',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonCount(1, 'data.devices')
            ->assertJsonPath('data.devices.0.device_id', 'my-device-01')
            ->assertJsonPath('data.devices.0.platform', 'android');
    }

    public function test_me_never_exposes_sensitive_fields(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

        $body = $response->json('data.user');

        // Staff-only and security fields must not leak to the mobile client.
        $this->assertArrayNotHasKey('role', $body);
        $this->assertArrayNotHasKey('last_ip', $body);
        $this->assertArrayNotHasKey('ban_reason', $body);
        $this->assertArrayNotHasKey('device_id', $body);
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->guest()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // AuthManager caches the RequestGuard instance (and its resolved user)
        // for the lifetime of the app instance. In tests the app persists across
        // requests, so we must forget the cached guard before the next request
        // — otherwise the deleted token's user is still returned.
        Auth::forgetGuards();

        // The same token must no longer work.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->guest()->create();
        $phoneToken = $user->createToken('phone')->plainTextToken;
        $tabletToken = $user->createToken('tablet')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Reset the cached guard so the next request re-resolves from the DB.
        Auth::forgetGuards();

        // The phone token is dead...
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        // Reset again before the tablet request.
        Auth::forgetGuards();

        // ...but the tablet keeps working.
        $this->withHeader('Authorization', 'Bearer '.$tabletToken)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    // ─── FCM token ────────────────────────────────────────────────────────────

    public function test_fcm_token_can_be_updated_for_a_registered_device(): void
    {
        $user = User::factory()->guest()->create();

        Device::factory()->forUser($user)->create([
            'device_id' => 'device-push-01',
            'fcm_token' => 'old-token',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/fcm-token', [
                'device_id' => 'device-push-01',
                'fcm_token' => 'new-token',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'device-push-01',
            'fcm_token' => 'new-token',
        ]);
    }

    public function test_fcm_token_update_rejects_an_unknown_device(): void
    {
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/fcm-token', [
                'device_id' => 'not-registered',
                'fcm_token' => 'new-token',
            ])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── Health ───────────────────────────────────────────────────────────────

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a signed Google-style ID token and stub Google's JWKS endpoint
     * so the verifier can validate it for real.
     *
     * The key pair is a checked-in fixture (tests/fixtures/google_test_key.pem)
     * rather than one generated at runtime, because OpenSSL key generation is
     * not reliably available on every development machine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeGoogleIdToken(array $overrides = []): string
    {
        $privateKey = file_get_contents(__DIR__.'/../../../fixtures/google_test_key.pem');
        $jwks = json_decode(file_get_contents(__DIR__.'/../../../fixtures/google_jwks.json'), true);

        // Google publishes its keys as a JWK set; the verifier parses it with
        // JWK::parseKeySet(), so the stub must be in that exact format.
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
        ]);

        $now = time();

        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-sub-123',
            'email' => 'player@dramaloop.test',
            'email_verified' => true,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $overrides);

        return JWT::encode($claims, $privateKey, 'RS256', 'test-key-id');
    }
}
