<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rewards economy as the admin has set it.
 *
 * Every earning and payout number — watch minutes, check-in, rewarded ads, the
 * lucky wheel, cash-out rules and payment methods — is read from here. Values
 * an admin has saved live in `app_settings` (one row per section); anything
 * never saved falls back to `config/rewards.php`.
 *
 * Only the stored rows are cached. Defaults are merged on every read, so a
 * config change (or a test's `config([...])`) applies immediately.
 */
class RewardSettings
{
    public const CACHE_KEY = 'reward_settings:v1';

    public const CACHE_TTL_SECONDS = 3600;

    public const SECTIONS = ['watch_reward', 'check_in', 'ad_reward', 'cash_out', 'lucky_wheel', 'payout_methods'];

    public const INPUT_TYPES = ['phone', 'email', 'text'];

    public const MAX_PRIZES = 12;

    /** Currencies paid out in whole units only. */
    private const ZERO_DECIMAL = ['MMK', 'JPY', 'KRW', 'VND', 'IDR'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'watch_reward' => [
                'enabled' => true,
                'points' => (int) config('rewards.watch_reward_points'),
                'interval_seconds' => (int) config('rewards.watch_reward_interval_seconds'),
                'daily_cap' => (int) config('rewards.daily_watch_reward_cap'),
            ],
            'check_in' => [
                'enabled' => true,
                'points' => (int) config('rewards.check_in_points'),
            ],
            'ad_reward' => [
                'enabled' => true,
                'points' => (int) config('rewards.ad_reward_points'),
                'daily_limit' => (int) config('rewards.daily_ad_limit'),
            ],
            'cash_out' => [
                'currency' => (string) config('rewards.currency', 'USD'),
                'points_per_unit' => (int) config('rewards.points_per_currency_unit'),
                'min_points' => (int) config('rewards.min_withdrawal_points'),
                'daily_limit' => (int) config('rewards.daily_withdrawal_limit'),
            ],
            'lucky_wheel' => [
                'enabled' => true,
                'daily_spins' => (int) config('rewards.lucky_wheel.daily_spins', 3),
                'prizes' => (array) config('rewards.lucky_wheel.prizes', []),
            ],
            'payout_methods' => (array) config('rewards.payout_methods', []),
        ];
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * Every section, stored values over defaults, normalised.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = self::defaults();
        $stored = $this->stored();

        $value = fn (string $section): array => array_key_exists($section, $stored) && is_array($stored[$section])
            ? ($section === 'payout_methods' ? $stored[$section] : array_replace($defaults[$section], $stored[$section]))
            : $defaults[$section];

        return [
            'watch_reward' => $this->normaliseWatch($value('watch_reward')),
            'check_in' => $this->normaliseCheckIn($value('check_in')),
            'ad_reward' => $this->normaliseAdReward($value('ad_reward')),
            'cash_out' => $this->normaliseCashOut($value('cash_out')),
            'lucky_wheel' => $this->normaliseWheel($value('lucky_wheel')),
            'payout_methods' => $this->normaliseMethods($value('payout_methods')),
        ];
    }

    /** @return array{enabled: bool, points: int, interval_seconds: int, daily_cap: int} */
    public function watchReward(): array
    {
        return $this->all()['watch_reward'];
    }

    /** @return array{enabled: bool, points: int} */
    public function checkIn(): array
    {
        return $this->all()['check_in'];
    }

    /** @return array{enabled: bool, points: int, daily_limit: int} */
    public function adReward(): array
    {
        return $this->all()['ad_reward'];
    }

    /** @return array{currency: string, points_per_unit: int, min_points: int, daily_limit: int} */
    public function cashOut(): array
    {
        return $this->all()['cash_out'];
    }

    /** @return array{enabled: bool, daily_spins: int, prizes: list<array{points: int, weight: int}>} */
    public function luckyWheel(): array
    {
        return $this->all()['lucky_wheel'];
    }

    /**
     * Payout methods, all of them or only those users may pick.
     *
     * @return list<array{code: string, name: string, account_label: string, account_hint: string, input: string, enabled: bool}>
     */
    public function payoutMethods(bool $enabledOnly = false): array
    {
        $methods = $this->all()['payout_methods'];

        return $enabledOnly
            ? array_values(array_filter($methods, fn (array $method): bool => $method['enabled']))
            : $methods;
    }

    /**
     * @return array{code: string, name: string, account_label: string, account_hint: string, input: string, enabled: bool}|null
     */
    public function payoutMethod(string $code): ?array
    {
        foreach ($this->payoutMethods() as $method) {
            if ($method['code'] === $code) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Decimal places for an amount in this currency.
     */
    public static function decimalsFor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    /**
     * Save the sections present in `$values`; the rest keep their value.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the fresh settings
     */
    public function update(array $values): array
    {
        DB::transaction(function () use ($values): void {
            foreach (self::SECTIONS as $section) {
                if (! array_key_exists($section, $values)) {
                    continue;
                }

                AppSetting::query()->updateOrCreate(
                    ['key' => $section],
                    [
                        'value' => $values[$section],
                        'group' => 'rewards',
                        'description' => 'Reward settings: '.str_replace('_', ' ', $section).'.',
                    ],
                );
            }
        });

        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    // ─── Building ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => AppSetting::query()->whereIn('key', self::SECTIONS)->pluck('value', 'key')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{enabled: bool, points: int, interval_seconds: int, daily_cap: int}
     */
    private function normaliseWatch(array $v): array
    {
        return [
            'enabled' => (bool) ($v['enabled'] ?? true),
            'points' => $this->int($v['points'] ?? 1, 1, 100000),
            'interval_seconds' => $this->int($v['interval_seconds'] ?? 60, 10, 3600),
            'daily_cap' => $this->int($v['daily_cap'] ?? 0, 0, 10000000),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{enabled: bool, points: int}
     */
    private function normaliseCheckIn(array $v): array
    {
        return [
            'enabled' => (bool) ($v['enabled'] ?? true),
            'points' => $this->int($v['points'] ?? 0, 0, 100000),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{enabled: bool, points: int, daily_limit: int}
     */
    private function normaliseAdReward(array $v): array
    {
        return [
            'enabled' => (bool) ($v['enabled'] ?? true),
            'points' => $this->int($v['points'] ?? 0, 0, 100000),
            'daily_limit' => $this->int($v['daily_limit'] ?? 0, 0, 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{currency: string, points_per_unit: int, min_points: int, daily_limit: int}
     */
    private function normaliseCashOut(array $v): array
    {
        $currency = strtoupper(trim((string) ($v['currency'] ?? 'USD')));

        return [
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD',
            'points_per_unit' => $this->int($v['points_per_unit'] ?? 1000, 1, 10000000),
            'min_points' => $this->int($v['min_points'] ?? 0, 0, 100000000),
            'daily_limit' => $this->int($v['daily_limit'] ?? 1, 1, 100),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{enabled: bool, daily_spins: int, prizes: list<array{points: int, weight: int}>}
     */
    private function normaliseWheel(array $v): array
    {
        $prizes = collect((array) ($v['prizes'] ?? []))
            ->filter(fn ($prize): bool => is_array($prize) && isset($prize['points']))
            ->map(fn (array $prize): array => [
                'points' => $this->int($prize['points'], 0, 100000),
                'weight' => $this->int($prize['weight'] ?? 1, 1, 100000),
            ])
            ->take(self::MAX_PRIZES)
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($v['enabled'] ?? true),
            'daily_spins' => $this->int($v['daily_spins'] ?? 3, 0, 100),
            'prizes' => $prizes,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $methods
     * @return list<array{code: string, name: string, account_label: string, account_hint: string, input: string, enabled: bool}>
     */
    private function normaliseMethods(array $methods): array
    {
        $out = [];

        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }

            $name = Str::limit(trim((string) ($method['name'] ?? '')), 40, '');
            $code = Str::limit(Str::slug((string) ($method['code'] ?? '') ?: $name, '_'), 16, '');

            if ($code === '' || $name === '' || isset($out[$code])) {
                continue;
            }

            $input = (string) ($method['input'] ?? 'text');

            $out[$code] = [
                'code' => $code,
                'name' => $name,
                'account_label' => Str::limit(trim((string) ($method['account_label'] ?? '')), 60, '') ?: $name.' account',
                'account_hint' => Str::limit(trim((string) ($method['account_hint'] ?? '')), 60, ''),
                'input' => in_array($input, self::INPUT_TYPES, true) ? $input : 'text',
                'enabled' => (bool) ($method['enabled'] ?? true),
            ];
        }

        return array_values($out);
    }

    private function int(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
