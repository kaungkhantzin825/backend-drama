<?php

/*
|--------------------------------------------------------------------------
| Rewards economy
|--------------------------------------------------------------------------
|
| The defaults for every number that decides how much a user earns or needs
| before cashing out. Admins override them from the "Reward settings" page
| (stored in `app_settings`, read through App\Services\RewardSettings) — and
| the Flutter client reads the effective values from /wallet.
|
*/

return [

    // How many points equal one unit of payout currency (USD).
    'points_per_currency_unit' => (int) env('REWARDS_POINTS_PER_USD', 1000),

    // Points awarded for the once-per-day check-in.
    'check_in_points' => (int) env('REWARDS_CHECKIN_POINTS', 20),

    // Points awarded per verified ad impression.
    'ad_reward_points' => (int) env('REWARDS_AD_POINTS', 10),

    // Fraud guard: how many rewarded ads one account may claim per day.
    'daily_ad_limit' => (int) env('REWARDS_DAILY_AD_LIMIT', 20),

    // Watch-time rewards: points credited per completed interval of playback.
    // The client reports how long it played; the server decides what that is
    // worth. Watch time is summed per user across videos, so a minute can be
    // finished on the next video. 1 point per completed minute of viewing
    // (each minute also triggers an ad break in the app, subject to the remote
    // ad interval).
    'watch_reward_points' => (int) env('REWARDS_WATCH_POINTS', 1),
    'watch_reward_interval_seconds' => (int) env('REWARDS_WATCH_INTERVAL', 60),

    // Fraud guard: total watch points one account may earn per day, so a
    // scripted player cannot farm the balance.
    'daily_watch_reward_cap' => (int) env('REWARDS_DAILY_WATCH_CAP', 100),

    // Fraud guard: the most watch time one heartbeat may claim. The app sends
    // one every 10 s; credited time also never runs ahead of the wall clock.
    'watch_max_seconds_per_ping' => (int) env('REWARDS_WATCH_MAX_SECONDS_PER_PING', 30),

    // Payout guard: the smallest balance that may be withdrawn.
    'min_withdrawal_points' => (int) env('REWARDS_MIN_WITHDRAWAL_POINTS', 5000),

    // Payout guard: how many withdrawals one account may request per day.
    'daily_withdrawal_limit' => (int) env('REWARDS_DAILY_WITHDRAWAL_LIMIT', 1),

    // Payout currency. Points convert at `points_per_currency_unit` per unit.
    'currency' => env('REWARDS_CURRENCY', 'USD'),

    // Payout methods the user can choose from. Every method collects an
    // account holder name plus one account field (`account_label`), checked as
    // an email address when `input` is "email".
    'payout_methods' => [
        ['code' => 'kbzpay', 'name' => 'KBZPay', 'account_label' => 'KBZPay phone number', 'account_hint' => '09xxxxxxxxx', 'input' => 'phone', 'enabled' => true],
        ['code' => 'wavepay', 'name' => 'WavePay', 'account_label' => 'WavePay phone number', 'account_hint' => '09xxxxxxxxx', 'input' => 'phone', 'enabled' => true],
        ['code' => 'paypal', 'name' => 'PayPal', 'account_label' => 'PayPal email', 'account_hint' => 'you@example.com', 'input' => 'email', 'enabled' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lucky wheel
    |--------------------------------------------------------------------------
    |
    | The wheel segments and their odds live here, not in the client — the app
    | renders whatever this list says and the server picks the winner. Weights
    | are relative, so they need not sum to 100.
    |
    */
    'lucky_wheel' => [
        'daily_spins' => (int) env('REWARDS_WHEEL_SPINS', 3),

        'prizes' => [
            ['points' => 5, 'weight' => 40],
            ['points' => 10, 'weight' => 25],
            ['points' => 20, 'weight' => 15],
            ['points' => 30, 'weight' => 10],
            ['points' => 50, 'weight' => 7],
            ['points' => 100, 'weight' => 3],
        ],
    ],

];
