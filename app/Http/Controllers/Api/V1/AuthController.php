<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleLoginRequest;
use App\Http\Requests\Api\V1\GuestLoginRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\UserResource;
use App\Services\Auth\InvalidTokenException;
use App\Services\AuthService;
use App\Services\DeviceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Mobile API authentication.
 *
 * All responses use the unified envelope:
 *   { "success": bool, "message": string, "data": any }
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DeviceService $devices,
    ) {}

    /**
     * POST /api/v1/auth/guest
     *
     * Device-scoped guest sign-in. Creates the account on first call and
     * reuses it on every later call from the same device.
     */
    public function guest(GuestLoginRequest $request): JsonResponse
    {
        $result = $this->auth->loginWithGuest($request->deviceAttributes());

        return ApiResponse::success('Signed in as guest.', [
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
        ]);
    }

    /**
     * POST /api/v1/auth/google
     *
     * Sign in with a Google ID token. The token is verified server-side.
     */
    public function google(GoogleLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->loginWithGoogle(
                $request->string('id_token')->toString(),
                $request->deviceAttributes(),
            );
        } catch (InvalidTokenException $e) {
            // The token is malformed, expired or issued for another app.
            return ApiResponse::error('The Google ID token could not be verified.', null, 401);
        } catch (RuntimeException $e) {
            // Server misconfiguration (missing client id, unreachable JWKS).
            report($e);

            return ApiResponse::error(
                'Google sign-in is temporarily unavailable. Please try again later.',
                null,
                503,
            );
        }

        return ApiResponse::success('Signed in with Google.', [
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the token that authenticated this request only.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success('Signed out.');
    }

    /**
     * GET /api/v1/me
     *
     * The authenticated user's profile, balance and registered devices.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['devices' => fn ($q) => $q->latest('last_active_at')->limit(10)]);

        return ApiResponse::success('OK', [
            'user' => new UserResource($user),
            'devices' => DeviceResource::collection($user->devices),
        ]);
    }

    /**
     * POST /api/v1/auth/fcm-token
     *
     * Update the push token for one of the user's devices.
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:128'],
            'fcm_token' => ['required', 'string', 'max:512'],
        ]);

        $device = $this->devices->updateFcmToken(
            $request->user(),
            $validated['device_id'],
            $validated['fcm_token'],
        );

        if ($device === null) {
            return ApiResponse::error('Unknown device.', null, 404);
        }

        return ApiResponse::success('Push token updated.', [
            'device' => new DeviceResource($device),
        ]);
    }
}
