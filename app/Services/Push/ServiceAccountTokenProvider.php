<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Mints FCM access tokens from a Firebase service-account JSON file.
 *
 * `google/auth` is already a direct dependency (it backs Google Sign-In
 * verification), so there is no extra package to install. Tokens are cached
 * for their lifetime — Google bills a token request, not a message send.
 */
class ServiceAccountTokenProvider implements AccessTokenProvider
{
    private ?string $cached = null;

    private ?int $expiresAt = null;

    public function token(): ?string
    {
        if ($this->cached !== null && $this->expiresAt !== null && $this->expiresAt > time() + 60) {
            return $this->cached;
        }

        $path = (string) config('firebase.credentials');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (! is_array($json)) {
            Log::warning('FCM credentials file is not valid JSON.', ['path' => $path]);

            return null;
        }

        try {
            $credentials = new ServiceAccountCredentials(
                (string) config('firebase.scope'),
                $json,
            );

            $result = $credentials->fetchAuthToken();

            $token = $result['access_token'] ?? null;

            if (! is_string($token) || $token === '') {
                return null;
            }

            $this->cached = $token;
            $this->expiresAt = time() + (int) ($result['expires_in'] ?? 3600);

            return $token;
        } catch (\Throwable $e) {
            Log::warning('Unable to mint an FCM access token.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
