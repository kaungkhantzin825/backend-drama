<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verifies Google ID tokens (the credential the Flutter app receives from
 * `google_sign_in` and sends to `POST /api/v1/auth/google`).
 *
 * The token is verified **server-side** against Google's published JWKS:
 *
 *  1. The JWT signature is checked against Google's public signing keys.
 *  2. `aud` must be one of our configured client IDs — this stops a token
 *     issued for a different app being replayed against ours.
 *  3. `iss` must be Google's issuer.
 *  4. `exp` / `nbf` are enforced by the JWT library.
 *
 * We never trust the client to tell us who the user is — the whole point of
 * issuing our own Sanctum token afterwards.
 */
class GoogleTokenVerifier
{
    /**
     * Google's JSON Web Key Set. The keys rotate, so we cache them briefly.
     */
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /**
     * Google's token issuer.
     */
    private const ISSUER = 'https://accounts.google.com';

    /**
     * The alternative issuer Google also emits.
     */
    private const ISSUER_ALT = 'accounts.google.com';

    /**
     * How long (seconds) the JWKS response is cached.
     */
    private const JWKS_CACHE_TTL = 3600;

    /**
     * The set of client IDs we accept. A token minted for any other app is
     * rejected.
     *
     * @var list<string>
     */
    private array $acceptedClientIds;

    /**
     * @param  list<string>  $clientIds
     */
    public function __construct(array $clientIds)
    {
        $this->acceptedClientIds = array_values(array_filter($clientIds));
    }

    /**
     * Verify an ID token and return its claims.
     *
     * @return array{sub: string, email: string, name?: string, picture?: string, email_verified?: bool}
     *
     * @throws InvalidTokenException  When the token is malformed, expired,
     *                                signed by an unknown key, issued for a
     *                                different client id, or otherwise invalid.
     */
    public function verify(string $idToken): array
    {
        if ($this->acceptedClientIds === []) {
            throw new RuntimeException(
                'No Google client IDs configured. Set GOOGLE_CLIENT_ID in .env.'
            );
        }

        try {
            $keys = JWK::parseKeySet($this->fetchJwks());

            $payload = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Google ID token could not be verified: '.$e->getMessage());
        }

        $claims = (array) $payload;

        // ─── Audience must be one of our client IDs ─────────────────────────
        // `aud` may be a string or an array depending on the token.
        $audience = (array) ($claims['aud'] ?? []);

        if (array_intersect($this->acceptedClientIds, $audience) === []) {
            throw new InvalidTokenException('Google ID token was issued for a different application.');
        }

        // ─── Issuer must be Google ──────────────────────────────────────────
        $issuer = (string) ($claims['iss'] ?? '');

        if (! in_array($issuer, [self::ISSUER, self::ISSUER_ALT], true)) {
            throw new InvalidTokenException('Google ID token has an unexpected issuer.');
        }

        // ─── Required claims ────────────────────────────────────────────────
        if (empty($claims['sub'])) {
            throw new InvalidTokenException('Google ID token is missing the "sub" claim.');
        }

        if (empty($claims['email'])) {
            throw new InvalidTokenException('Google ID token is missing the "email" claim.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => (string) $claims['email'],
            'name' => $claims['name'] ?? null,
            'picture' => $claims['picture'] ?? null,
            'email_verified' => (bool) ($claims['email_verified'] ?? false),
        ];
    }

    /**
     * Fetch Google's signing keys, caching them to avoid hammering the
     * endpoint on every sign-in.
     *
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        $cached = Cache::get('google.jwks');

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = Http::timeout(10)->get(self::JWKS_URL);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to retrieve Google signing keys.');
        }

        $jwks = $response->json();

        if (! is_array($jwks) || ! isset($jwks['keys'])) {
            throw new RuntimeException('Google signing keys response was malformed.');
        }

        Cache::put('google.jwks', $jwks, self::JWKS_CACHE_TTL);

        return $jwks;
    }
}
