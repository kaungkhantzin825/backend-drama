<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the FCM HTTP v1 API.
 *
 * FCM v1 takes exactly one message per request, so a fan-out to several devices
 * is a loop. That is fine here: a user has a handful of devices at most, and
 * the whole thing runs on a queue worker rather than in a request.
 *
 * Nothing in this class throws. Push is a courtesy, never a transaction — a
 * dead network must not roll back a payout.
 */
class FirebaseMessaging
{
    public function __construct(private readonly AccessTokenProvider $tokens) {}

    /**
     * True when enough config is present to attempt a send.
     *
     * Deliberately does not call Google — it only reads config.
     */
    public function isConfigured(): bool
    {
        return (bool) config('firebase.enabled')
            && (string) config('firebase.project_id') !== ''
            && (string) config('firebase.credentials') !== '';
    }

    /**
     * Push one notification to a set of device tokens.
     *
     * @param  list<string>  $deviceTokens
     * @param  array<string, scalar|null>  $data
     * @return array{sent: int, invalid: list<string>}
     */
    public function send(array $deviceTokens, string $title, ?string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($deviceTokens)));

        if ($tokens === [] || ! $this->isConfigured()) {
            return ['sent' => 0, 'invalid' => []];
        }

        $accessToken = $this->tokens->token();

        if ($accessToken === null) {
            return ['sent' => 0, 'invalid' => []];
        }

        $url = sprintf((string) config('firebase.endpoint'), (string) config('firebase.project_id'));

        $sent = 0;
        $invalid = [];

        foreach ($tokens as $deviceToken) {
            $outcome = $this->sendOne($url, $accessToken, $deviceToken, $title, $body, $data);

            if ($outcome === 'sent') {
                $sent++;
            } elseif ($outcome === 'invalid') {
                $invalid[] = $deviceToken;
            }
        }

        return ['sent' => $sent, 'invalid' => $invalid];
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return 'sent'|'invalid'|'failed'
     */
    private function sendOne(
        string $url,
        string $accessToken,
        string $deviceToken,
        string $title,
        ?string $body,
        array $data,
    ): string {
        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('firebase.timeout'))
                ->acceptJson()
                ->post($url, [
                    'message' => $this->buildMessage($deviceToken, $title, $body, $data),
                ]);
        } catch (\Throwable $e) {
            Log::warning('FCM send failed.', ['error' => $e->getMessage()]);

            return 'failed';
        }

        if ($response->successful()) {
            return 'sent';
        }

        // 404 / UNREGISTERED means the app was uninstalled or the token rotated.
        // The caller prunes it so we stop paying for dead sends.
        $errorStatus = (string) $response->json('error.status');

        if ($response->status() === 404 || $errorStatus === 'NOT_FOUND' || $errorStatus === 'UNREGISTERED') {
            return 'invalid';
        }

        Log::warning('FCM rejected a message.', [
            'status' => $response->status(),
            'error' => $response->json('error.status'),
        ]);

        return 'failed';
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>
     */
    private function buildMessage(string $deviceToken, string $title, ?string $body, array $data): array
    {
        // FCM data values must be strings; nulls are dropped rather than
        // serialised as the literal "null".
        $payload = [];

        foreach ($data as $key => $value) {
            if ($value !== null) {
                $payload[$key] = (string) $value;
            }
        }

        $notification = ['title' => $title];

        if ($body !== null && $body !== '') {
            $notification['body'] = $body;
        }

        return [
            'token' => $deviceToken,
            'notification' => $notification,
            'data' => $payload,
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => (string) config('firebase.android_channel'),
                    'default_sound' => true,
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => ['sound' => 'default'],
                ],
            ],
        ];
    }
}
