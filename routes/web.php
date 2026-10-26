<?php

use App\Http\Controllers\Admin\AdEventController as AdminAdEventController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AppConfigController as AdminAppConfigController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\DramaController as AdminDramaController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\RewardSettingsController as AdminRewardSettingsController;
use App\Http\Controllers\Admin\RewardController as AdminRewardController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\TrafficController as AdminTrafficController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — Admin panel (Inertia + Vue 3)
|--------------------------------------------------------------------------
*/

// The root URL goes straight to the admin panel, so there is no dead end
// showing the framework's default welcome page.
Route::get('/', function () {
    return redirect()->route('admin.dashboard');
})->name('home');

/*
|--------------------------------------------------------------------------
| Admin authentication
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AdminAuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware('admin.auth')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/', [AdminAuthController::class, 'dashboard'])->name('dashboard');

        /*
        |----------------------------------------------------------------------
        | Admin panel screens (Inertia)
        |----------------------------------------------------------------------
        */
        Route::middleware('admin.role:admin,editor')->group(function () {
            Route::get('content', fn () => inertia('admin/Content'))->name('content');
        });

        Route::middleware('admin.role:admin')->group(function () {
            Route::get('users', fn () => inertia('admin/Users'))->name('users');
            Route::get('rewards', fn () => inertia('admin/Rewards'))->name('rewards');
            Route::get('withdrawals', fn () => inertia('admin/Withdrawals'))->name('withdrawals');
            Route::get('ads', fn () => inertia('admin/Ads'))->name('ads');
            Route::get('traffic', fn () => inertia('admin/Traffic'))->name('traffic');
            Route::get('settings', fn () => inertia('admin/Settings'))->name('settings');
            Route::get('reward-settings', fn () => inertia('admin/RewardSettings'))->name('reward-settings');
            Route::get('feedback', fn () => inertia('admin/Feedback'))->name('feedback');
        });

        /*
        |----------------------------------------------------------------------
        | Admin JSON API
        |----------------------------------------------------------------------
        |
        | Consumed by the Inertia screens above. They live under the `web`
        | middleware group so the session-based `admin` guard applies, and are
        | namespaced under /admin/api so they can never be confused with the
        | public mobile API under /api/v1.
        |
        */
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('stats', AdminStatsController::class)->name('stats');

            Route::middleware('admin.role:admin,editor')->group(function () {
                Route::apiResource('categories', AdminCategoryController::class);

                Route::post('dramas/{drama}/publish', [AdminDramaController::class, 'publish'])
                    ->name('dramas.publish');

                Route::apiResource('dramas', AdminDramaController::class);
            });

            // Revenue-critical areas — admins only.
            Route::middleware('admin.role:admin')->group(function () {
                Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
                Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
                Route::post('users/{user}/ban', [AdminUserController::class, 'ban'])->name('users.ban');
                Route::delete('users/{user}/ban', [AdminUserController::class, 'unban'])->name('users.unban');

                Route::get('rewards/stats', [AdminRewardController::class, 'stats'])->name('rewards.stats');
                Route::get('rewards/ledger', [AdminRewardController::class, 'ledger'])->name('rewards.ledger');

                Route::get('withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
                Route::post('withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])
                    ->name('withdrawals.approve');
                Route::post('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])
                    ->name('withdrawals.reject');
                Route::post('withdrawals/{withdrawal}/paid', [AdminWithdrawalController::class, 'markPaid'])
                    ->name('withdrawals.paid');

                Route::get('ad-events', [AdminAdEventController::class, 'index'])->name('ad-events.index');

                Route::get('app-config', [AdminAppConfigController::class, 'show'])->name('app-config.show');
                Route::put('app-config', [AdminAppConfigController::class, 'update'])->name('app-config.update');

                Route::get('reward-settings', [AdminRewardSettingsController::class, 'show'])->name('reward-settings.show');
                Route::put('reward-settings', [AdminRewardSettingsController::class, 'update'])->name('reward-settings.update');

                Route::get('feedback/settings', [AdminFeedbackController::class, 'settings'])->name('feedback.settings');
                Route::put('feedback/settings', [AdminFeedbackController::class, 'updateSettings'])->name('feedback.settings.update');
                Route::get('feedback', [AdminFeedbackController::class, 'index'])->name('feedback.index');
                Route::post('feedback/{feedback}/read', [AdminFeedbackController::class, 'markRead'])->name('feedback.read');

                // ─── Traffic monitoring ──────────────────────────────────────
                Route::prefix('traffic')->name('traffic.')->group(function () {
                    Route::get('activity', [AdminTrafficController::class, 'activity'])->name('activity');
                    Route::get('api', [AdminTrafficController::class, 'api'])->name('api');
                    Route::get('ads', [AdminTrafficController::class, 'ads'])->name('ads');
                    Route::get('ab', [AdminTrafficController::class, 'ab'])->name('ab');
                    Route::get('export', [AdminTrafficController::class, 'export'])->name('export');

                    Route::get('flags', [AdminTrafficController::class, 'flags'])->name('flags.index');
                    Route::post('flags/{flag}/block', [AdminTrafficController::class, 'block'])->name('flags.block');
                    Route::post('flags/{flag}/dismiss', [AdminTrafficController::class, 'dismiss'])->name('flags.dismiss');
                    Route::post('flags/{flag}/unblock', [AdminTrafficController::class, 'unblock'])->name('flags.unblock');
                    Route::post('scan', [AdminTrafficController::class, 'scan'])->name('scan');
                });
            });
        });
    });
});
