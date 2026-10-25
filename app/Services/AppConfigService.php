<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remote configuration for the mobile app.
 *
 * Covers the ad setup (switch, interval, per-placement rules, waterfalls), the
 * release policy (minimum / latest app version, maintenance mode) and an
 * optional A/B experiment on the ad settings.
 *
 * The client asks for this on every cold start, so it is served from cache:
 * one DB read per hour at most, and none at all between admin edits. Writes go
 * through `AppSetting`, whose model events drop the cache key, so an edit is
 * visible on the very next request rather than after the TTL.
 *
 * Ad units are a **waterfall**: each format holds an ordered list, highest
 * eCPM floor first. The app requests the first unit, falls through to the next
 * on a no-fill, and stops at the first one that loads. Third-party networks
 * plug in through AdMob mediation (configured in the AdMob console), so every
 * entry here is an AdMob unit id.
 *
 * **A/B experiment.** When enabled, each device is hashed into a variant
 * (stable for the life of the experiment) and that variant's overrides are
 * layered on top of the base config. The variant key comes back as `ab_group`;
 * the app echoes it on every request, so traffic, activity and ad revenue can
 * be compared per variant.
 */
class AppConfigService
{
    public const CACHE_KEY = 'app_config:v2';

    public const CACHE_TTL_SECONDS = 3600;

    public const PLATFORMS = ['android', 'ios'];

    public const FORMATS = ['banner', 'interstitial', 'rewarded'];

    public const PROVIDERS = ['admob'];

    /** Settings an experiment variant may override. */
    public const OVERRIDABLE = [
        'is_ads_enabled',
        'ad_interval_seconds',
        'ad_load_timeout_seconds',
        'ad_units',
        'ad_rules',
    ];

    /**
     * What the app gets when a key has never been saved.
     *
     * The unit ids are Google's published **test** units: they always fill,
     * are labelled "Test Ad", and are never billed. Replace them from the
     * admin panel before going live.
     *
     * @return array<string, array{value: mixed, group: string, description: string}>
     */
    public static function defaults(): array
    {
        return [
            'is_ads_enabled' => [
                'value' => true,
                'group' => 'ads',
                'description' => 'Master switch. Off = the app shows no ads at all.',
            ],
            'ad_provider' => [
                'value' => 'admob',
                'group' => 'ads',
                'description' => 'Ad SDK the app loads. Other networks run via AdMob mediation.',
            ],
            'ad_interval_seconds' => [
                // Matches the 1-minute watch reward: one ad per minute watched.
                'value' => 60,
                'group' => 'ads',
                'description' => 'Minimum seconds between two interstitials.',
            ],
            'ad_load_timeout_seconds' => [
                'value' => 5,
                'group' => 'ads',
                'description' => 'How long one waterfall step may take before falling through to the next.',
            ],
            'ad_units' => [
                'value' => [
                    'android' => [
                        'banner' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/6300978111', 'label' => 'Test banner'],
                        ],
                        'interstitial' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/1033173712', 'label' => 'Tier 1 — test image'],
                            ['unit_id' => 'ca-app-pub-3940256099942544/8691691433', 'label' => 'Tier 2 — test video'],
                        ],
                        'rewarded' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/5224354917', 'label' => 'Test rewarded'],
                        ],
                    ],
                    'ios' => [
                        'banner' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/2934735716', 'label' => 'Test banner'],
                        ],
                        'interstitial' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/4411468910', 'label' => 'Tier 1 — test image'],
                            ['unit_id' => 'ca-app-pub-3940256099942544/5135589807', 'label' => 'Tier 2 — test video'],
                        ],
                        'rewarded' => [
                            ['unit_id' => 'ca-app-pub-3940256099942544/1712485313', 'label' => 'Test rewarded'],
                        ],
                    ],
                ],
                'group' => 'ads',
                'description' => 'Waterfall per platform and format, highest eCPM floor first.',
            ],
            'ad_rules' => [
                'value' => [
                    'interstitial' => [
                        // No interstitial until the viewer has swiped past
                        // this many videos in the session — let them get
                        // hooked. (Key name kept from the episode era so
                        // stored settings stay valid.)
                        'first_after_episodes' => 1,
                        'max_per_session' => 10,
                        'max_per_day' => 40,
                    ],
                    'rewarded' => ['max_per_day' => 20],
                    'app_open' => ['enabled' => true],
                    'banner' => ['enabled' => true],
                ],
                'group' => 'ads',
                'description' => 'Per-placement frequency rules enforced by the app.',
            ],
            'app_version' => [
                'value' => [
                    // Below this the app shows a blocking "update required"
                    // screen and the API answers 426.
                    'min_supported' => '1.0.0',
                    // Below this the app offers an optional update.
                    'latest' => '1.0.0',
                    'update_url_android' => '',
                    'update_url_ios' => '',
                    'update_message' => 'A new version of DramaLoop is available with fixes and improvements.',
                ],
                'group' => 'app',
                'description' => 'Release policy: forced and optional updates.',
            ],
            'maintenance' => [
                'value' => [
                    'enabled' => false,
                    'message' => "We're upgrading DramaLoop. Please check back in a few minutes.",
                ],
                'group' => 'app',
                'description' => 'Maintenance mode: the app shows the message and the API answers 503.',
            ],
            'ad_experiment' => [
                'value' => [
                    'enabled' => false,
                    'name' => 'interval_test',
                    'variants' => [
                        ['key' => 'A', 'weight' => 50, 'overrides' => []],
                        ['key' => 'B', 'weight' => 50, 'overrides' => ['ad_interval_seconds' => 60]],
                    ],
                ],
                'group' => 'ads',
                'description' => 'A/B test: devices are split across variants with different ad settings.',
            ],
        ];
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * The payload served to one device: the cached base config, with its
     * experiment variant (if any) applied.
     *
     * @return array<string, mixed>
     */
    public function forClient(?string $deviceId = null): array
    {
        $cached = $this->cached();
        $payload = $cached['payload'];
        $experiment = $cached['experiment'];

        $payload['ab_test'] = null;
        $payload['ab_group'] = null;

        if ($experiment['enabled'] && $deviceId !== null && $deviceId !== '' && $experiment['variants'] !== []) {
            $variant = $this->assignVariant($experiment, $deviceId);
            $payload = $this->applyOverrides($payload, $variant['overrides']);
            $payload['ab_test'] = $experiment['name'];
            $payload['ab_group'] = $variant['key'];
        }

        return $this->stamp($payload);
    }

    /**
     * The base config without any experiment applied — what the version and
     * maintenance middleware read.
     *
     * @return array<string, mixed>
     */
    public function base(): array
    {
        return $this->cached()['payload'];
    }

    /**
     * Everything the admin editor needs, including the experiment definition.
     *
     * @return array<string, mixed>
     */
    public function adminView(): array
    {
        $cached = $this->cached();

        return $this->stamp($cached['payload']) + ['ad_experiment' => $cached['experiment']];
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    /**
     * Apply an admin edit. Only the keys present in `$values` change.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the fresh admin view
     */
    public function update(array $values): array
    {
        $defaults = self::defaults();

        DB::transaction(function () use ($values, $defaults): void {
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $defaults)) {
                    continue;
                }

                AppSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'group' => $defaults[$key]['group'],
                        'description' => $defaults[$key]['description'],
                    ],
                );
            }
        });

        // Model events already forgot the key; doing it once more after the
        // commit guards against a concurrent request re-caching stale rows
        // between the first save and the commit.
        Cache::forget(self::CACHE_KEY);

        return $this->adminView();
    }

    // ─── Building ────────────────────────────────────────────────────────────

    /**
     * @return array{payload: array<string, mixed>, experiment: array<string, mixed>}
     */
    private function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => $this->build());
    }

    /**
     * Read every setting, fill gaps from the defaults, and shape the payload.
     *
     * @return array{payload: array<string, mixed>, experiment: array<string, mixed>}
     */
    private function build(): array
    {
        $stored = AppSetting::query()->pluck('value', 'key')->all();

        $value = function (string $key) use ($stored): mixed {
            return array_key_exists($key, $stored)
                ? $stored[$key]
                : self::defaults()[$key]['value'];
        };

        return [
            'payload' => [
                'is_ads_enabled' => (bool) $value('is_ads_enabled'),
                'ad_provider' => (string) $value('ad_provider'),
                'ad_interval_seconds' => max(0, (int) $value('ad_interval_seconds')),
                'ad_load_timeout_seconds' => max(1, (int) $value('ad_load_timeout_seconds')),
                'ad_units' => $this->normaliseUnits((array) $value('ad_units')),
                'ad_rules' => $this->normaliseRules((array) $value('ad_rules')),
                'app_version' => $this->normaliseVersion((array) $value('app_version')),
                'maintenance' => $this->normaliseMaintenance((array) $value('maintenance')),
            ],
            'experiment' => $this->normaliseExperiment((array) $value('ad_experiment')),
        ];
    }

    /**
     * Lets the client (and support) tell at a glance which config a device is
     * running, without diffing the whole payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stamp(array $payload): array
    {
        unset($payload['config_version']);
        $payload['config_version'] = substr(md5((string) json_encode($payload)), 0, 12);

        return $payload;
    }

    // ─── Experiment ──────────────────────────────────────────────────────────

    /**
     * Deterministic weighted bucketing: the same device always lands in the
     * same variant for a given experiment name, and renaming the experiment
     * reshuffles everyone.
     *
     * @param  array<string, mixed>  $experiment
     * @return array{key: string, weight: int, overrides: array<string, mixed>}
     */
    private function assignVariant(array $experiment, string $deviceId): array
    {
        $variants = $experiment['variants'];
        $total = array_sum(array_column($variants, 'weight'));
        $roll = crc32($experiment['name'].':'.$deviceId) % max(1, $total);

        $cursor = 0;
        foreach ($variants as $variant) {
            $cursor += $variant['weight'];
            if ($roll < $cursor) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applyOverrides(array $payload, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            switch ($key) {
                case 'is_ads_enabled':
                    $payload[$key] = (bool) $value;
                    break;
                case 'ad_interval_seconds':
                    $payload[$key] = max(0, (int) $value);
                    break;
                case 'ad_load_timeout_seconds':
                    $payload[$key] = max(1, (int) $value);
                    break;
                case 'ad_rules':
                    $payload[$key] = $this->normaliseRules(array_replace_recursive($payload[$key], (array) $value));
                    break;
                case 'ad_units':
                    // A variant replaces only the waterfalls it names.
                    foreach ((array) $value as $platform => $formats) {
                        foreach ((array) $formats as $format => $units) {
                            if (isset($payload['ad_units'][$platform][$format])) {
                                $payload['ad_units'][$platform][$format] =
                                    $this->normaliseUnits([$platform => [$format => $units]])[$platform][$format];
                            }
                        }
                    }
                    break;
            }
        }

        return $payload;
    }

    // ─── Normalisers ─────────────────────────────────────────────────────────

    /**
     * Always return every platform and format, each as an ordered list, so the
     * client never has to null-check its way through the structure.
     *
     * @param  array<string, mixed>  $units
     * @return array<string, array<string, list<array{unit_id: string, label: string|null}>>>
     */
    private function normaliseUnits(array $units): array
    {
        $out = [];

        foreach (self::PLATFORMS as $platform) {
            foreach (self::FORMATS as $format) {
                $out[$platform][$format] = collect($units[$platform][$format] ?? [])
                    ->filter(fn ($entry): bool => is_array($entry) && filled($entry['unit_id'] ?? null))
                    ->map(fn (array $entry): array => [
                        'unit_id' => (string) $entry['unit_id'],
                        'label' => isset($entry['label']) ? (string) $entry['label'] : null,
                    ])
                    ->values()
                    ->all();
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<string, int|bool>>
     */
    private function normaliseRules(array $rules): array
    {
        $d = self::defaults()['ad_rules']['value'];
        $int = fn (string $format, string $key): int => max(0, (int) ($rules[$format][$key] ?? $d[$format][$key]));

        return [
            'interstitial' => [
                'first_after_episodes' => $int('interstitial', 'first_after_episodes'),
                'max_per_session' => $int('interstitial', 'max_per_session'),
                'max_per_day' => $int('interstitial', 'max_per_day'),
            ],
            'rewarded' => ['max_per_day' => $int('rewarded', 'max_per_day')],
            'app_open' => ['enabled' => (bool) ($rules['app_open']['enabled'] ?? $d['app_open']['enabled'])],
            'banner' => ['enabled' => (bool) ($rules['banner']['enabled'] ?? $d['banner']['enabled'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, string>
     */
    private function normaliseVersion(array $version): array
    {
        $d = self::defaults()['app_version']['value'];

        $out = [];
        foreach ($d as $key => $default) {
            $out[$key] = (string) ($version[$key] ?? $default);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @return array{enabled: bool, message: string}
     */
    private function normaliseMaintenance(array $maintenance): array
    {
        $d = self::defaults()['maintenance']['value'];

        return [
            'enabled' => (bool) ($maintenance['enabled'] ?? $d['enabled']),
            'message' => (string) ($maintenance['message'] ?? $d['message']),
        ];
    }

    /**
     * @param  array<string, mixed>  $experiment
     * @return array{enabled: bool, name: string, variants: list<array{key: string, weight: int, overrides: array<string, mixed>}>}
     */
    private function normaliseExperiment(array $experiment): array
    {
        $variants = collect($experiment['variants'] ?? [])
            ->filter(fn ($v): bool => is_array($v) && filled($v['key'] ?? null) && (int) ($v['weight'] ?? 0) > 0)
            ->map(fn (array $v): array => [
                'key' => (string) $v['key'],
                'weight' => (int) $v['weight'],
                'overrides' => array_intersect_key((array) ($v['overrides'] ?? []), array_flip(self::OVERRIDABLE)),
            ])
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($experiment['enabled'] ?? false),
            'name' => (string) ($experiment['name'] ?? 'experiment'),
            'variants' => $variants,
        ];
    }
}
