<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\DeviceRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\InvalidTokenException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business logic for the mobile authentication flows.
 *
 * Two entry points:
 *  - `loginWithGuest()`  — device-scoped anonymous account.
 *  - `loginWithGoogle()` — verified Google ID token.
 *
 * Both converge on the same shape of result: a user plus a freshly issued
 * Sanctum token. Controllers stay thin by delegating here.
 */
class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DeviceRepository $devices,
        private readonly DeviceService $deviceService,
        private readonly GoogleTokenVerifier $google,
    ) {
    }

    /**
     * Sign in (or create) a guest account bound to a device.
     *
     * The device id is the identity anchor. If we have seen this device
     * before we reuse its guest account, so reinstalling the app does not
     * wipe the user's points or watch history.
     *
     * @param  array{device_id: string, platform?: string, app_version?: string, fcm_token?: string}  $attributes
     */
    public function loginWithGuest(array $attributes): array
    {
        $deviceId = $attributes['device_id'];

        $user = DB::transaction(function () use ($deviceId): ?User {
            // Reuse the existing guest account for this device.
            $existing = $this->users->findGuestByDeviceId($deviceId);

            if ($existing !== null) {
                // A guest account that has since been linked to Google is no
                // longer a guest — fall through and create a fresh guest.
                if ($existing->google_id !== null) {
                    return null;
                }

                return $existing;
            }

            return $this->users->create([
                'name' => 'Guest '.Str::upper(Str::random(6)),
                'email' => null,
                'password' => null,
                'device_id' => $deviceId,
                'points_balance' => 0,
            ]);
        });

        if ($user === null) {
            // The device previously belonged to a Google account. Create a
            // separate guest account for it so the two histories stay apart.
            $user = $this->users->create([
                'name' => 'Guest '.Str::upper(Str::random(6)),
                'email' => null,
                'password' => null,
                'device_id' => $deviceId,
                'points_balance' => 0,
            ]);
        }

        $this->deviceService->register($user, $attributes);
        $this->users->touchLastSeen($user);

        return $this->issueToken($user, 'guest');
    }

    /**
     * Sign in with a Google ID token.
     *
     * The token is verified against Google's public keys. We then:
     *  1. Find the account linked to that Google identity, or
     *  2. Create one, or
     *  3. Upgrade a guest account that used the same email.
     *
     * @param  array{device_id?: string, platform?: string, app_version?: string, fcm_token?: string}  $attributes
     *
     * @throws InvalidTokenException
     */
    public function loginWithGoogle(string $idToken, array $attributes): array
    {
        $claims = $this->google->verify($idToken);

        $user = DB::transaction(function () use ($claims, $attributes): User {
            $user = $this->users->findByGoogleId($claims['sub']);

            if ($user === null) {
                $user = $this->users->findByEmail($claims['email']);

                // Guard against linking to a staff account.
                if ($user !== null && $user->isStaff()) {
                    $user = null;
                }
            }

            if ($user === null) {
                $user = $this->users->create([
                    'name' => $claims['name'] ?? Str::before($claims['email'], '@'),
                    'email' => $claims['email'],
                    'google_id' => $claims['sub'],
                    'avatar_url' => $claims['picture'] ?? null,
                    'email_verified_at' => ($claims['email_verified'] ?? false) ? now() : null,
                    'device_id' => $attributes['device_id'] ?? null,
                    'points_balance' => 0,
                ]);
            } else {
                // Refresh the mutable profile fields on each sign-in.
                $user->fill([
                    'google_id' => $claims['sub'],
                    'avatar_url' => $claims['picture'] ?? $user->avatar_url,
                    'name' => $claims['name'] ?? $user->name,
                ]);

                if ($claims['email_verified'] ?? false) {
                    $user->email_verified_at = $user->email_verified_at ?? now();
                }

                $user->save();
            }

            return $user;
        });

        if (isset($attributes['device_id'])) {
            $this->deviceService->register($user, $attributes);
        }

        $this->users->touchLastSeen($user);

        return $this->issueToken($user, 'google');
    }

    /**
     * Issue a Sanctum personal access token for the given user.
     *
     * @return array{user: User, token: string, token_type: string}
     */
    public function issueToken(User $user, string $deviceName = 'mobile'): array
    {
        // Each device gets its own token so we can revoke one device without
        // signing the user out everywhere.
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Revoke the token that authenticated the current request.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }

    /**
     * Revoke every token for a user (used on ban / account deletion).
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Whether a user may sign in at all.
     */
    public function isBanned(User $user): bool
    {
        return $user->banned_at !== null;
    }
}
