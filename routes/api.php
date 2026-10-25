<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DramaController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\LuckyWheelController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\TrafficController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Mobile Application
|--------------------------------------------------------------------------
|
| Every response uses the unified envelope:
|   { "success": bool, "message": string, "data": any }
|
| Authentication is stateless via Laravel Sanctum personal access tokens.
| The client sends: Authorization: Bearer <token>
|
*/

/*
|--------------------------------------------------------------------------
| Public endpoints
|--------------------------------------------------------------------------
*/

// Device-scoped guest sign-in and Google sign-in.
// Both are rate limited harder than the default `api` limiter because they
// are the entry point for credential stuffing and token replay attempts.
Route::prefix('v1/auth')->middleware('throttle:20,1')->group(function () {
    Route::post('guest', [AuthController::class, 'guest'])->name('api.v1.auth.guest');
    Route::post('google', [AuthController::class, 'google'])->name('api.v1.auth.google');
});

// Lightweight connectivity probe used by the Flutter app on cold start.
Route::get('v1/health', function (Request $request) {
    return response()->json([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'status' => 'ok',
            'version' => config('app.version', '1.0.0'),
            'time' => now()->toIso8601String(),
        ],
    ]);
})->name('api.v1.health');

/*
|--------------------------------------------------------------------------
| Public catalogue (Phase 2)
|--------------------------------------------------------------------------
|
| Browsing is public so the app can render its home screen while the guest
| token is still being issued on cold start. Only *published* dramas are
| returned — see DramaRepository::published().
|
*/

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    // Remote config (ads on/off, interstitial interval, ad unit waterfall).
    // Public and cached — the app reads it on cold start, before sign-in.
    Route::get('app-config', [AppConfigController::class, 'show'])->name('api.v1.app-config');

    // Ad waterfall telemetry (request/fill/impression/click/revenue), batched.
    // Public: ads load before sign-in. Token optional — see TrafficController.
    Route::post('ads/traffic', [TrafficController::class, 'adTraffic'])
        ->middleware('throttle:30,1')
        ->name('api.v1.ads.traffic');

    // Home screen: featured / latest / trending rails + category chips.
    Route::get('home', [HomeController::class, 'index'])->name('api.v1.home');

    Route::get('categories', [CategoryController::class, 'index'])->name('api.v1.categories.index');

    // Browse with ?category=slug&q=&featured=1&sort=latest|popular|rating|title
    // Each item carries its stream URL; the app's swipe feed pages this list.
    Route::get('dramas', [DramaController::class, 'index'])->name('api.v1.dramas.index');
    Route::get('dramas/{slug}', [DramaController::class, 'show'])->name('api.v1.dramas.show');
});

/*
|--------------------------------------------------------------------------
| Authenticated endpoints
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
    Route::post('auth/fcm-token', [AuthController::class, 'updateFcmToken'])
        ->name('api.v1.auth.fcm-token');

    // ─── Wallet & rewards ────────────────────────────────────────────────────
    Route::get('wallet', [RewardController::class, 'wallet'])->name('api.v1.wallet');
    Route::get('wallet/ledger', [RewardController::class, 'ledger'])->name('api.v1.wallet.ledger');
    Route::post('rewards/check-in', [RewardController::class, 'checkIn'])
        ->middleware('throttle:rewards')
        ->name('api.v1.rewards.check-in');
    Route::post('ads/events', [RewardController::class, 'adEvent'])
        ->middleware('throttle:ad-events')
        ->name('api.v1.ads.events');

    // ─── Lucky wheel ─────────────────────────────────────────────────────────
    Route::get('rewards/lucky-wheel', [LuckyWheelController::class, 'show'])
        ->name('api.v1.rewards.lucky-wheel');
    Route::post('rewards/lucky-wheel/spin', [LuckyWheelController::class, 'spin'])
        ->middleware('throttle:rewards')
        ->name('api.v1.rewards.lucky-wheel.spin');

    // ─── Withdrawals ─────────────────────────────────────────────────────────
    Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('api.v1.withdrawals.index');
    Route::post('withdrawals', [WithdrawalController::class, 'store'])
        ->middleware('throttle:withdraw')
        ->name('api.v1.withdrawals.store');

    // ─── Library: progress, history, my list ─────────────────────────────────
    Route::get('continue-watching', [LibraryController::class, 'continueWatching'])
        ->name('api.v1.continue-watching');
    Route::get('history', [LibraryController::class, 'history'])
        ->name('api.v1.history');
    Route::get('favorites', [LibraryController::class, 'favorites'])
        ->name('api.v1.favorites');
    Route::post('dramas/{drama}/progress', [LibraryController::class, 'progress'])
        ->whereNumber('drama')
        ->middleware('throttle:progress')
        ->name('api.v1.dramas.progress');
    Route::post('dramas/{drama}/favorite', [LibraryController::class, 'toggleFavorite'])
        ->whereNumber('drama')
        ->name('api.v1.dramas.favorite');

    // ─── Activity ────────────────────────────────────────────────────────────
    Route::post('activity/session', [TrafficController::class, 'session'])
        ->middleware('throttle:10,1')
        ->name('api.v1.activity.session');

    // ─── Notifications ───────────────────────────────────────────────────────
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('api.v1.notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.notifications.unread-count');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('api.v1.notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereNumber('notification')
        ->name('api.v1.notifications.read');
});
