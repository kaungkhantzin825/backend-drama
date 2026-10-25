<?php

namespace App\Http\Requests\Admin;

use App\Services\AppConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin edit of the remote config.
 *
 * Every key is optional (`sometimes`), so the panel can save one section at a
 * time. Unit ids are checked against AdMob's format because a typo here would
 * silently stop every ad in the app.
 */
class AppConfigRequest extends FormRequest
{
    /** Dotted numeric version: 1, 1.4, 1.4.2 or 1.4.2.7. */
    private const VERSION = '/^\d+(\.\d+){0,3}$/';

    public function authorize(): bool
    {
        return true; // Route middleware restricts this to admins.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $platforms = implode(',', AppConfigService::PLATFORMS);
        $formats = implode(',', AppConfigService::FORMATS);

        return [
            'is_ads_enabled' => ['sometimes', 'boolean'],
            'ad_provider' => ['sometimes', 'string', Rule::in(AppConfigService::PROVIDERS)],
            'ad_interval_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'ad_load_timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],

            'ad_units' => ['sometimes', 'array:'.$platforms],
            'ad_units.*' => ['array:'.$formats],
            // A waterfall longer than five steps only adds latency.
            'ad_units.*.*' => ['array', 'max:5'],
            'ad_units.*.*.*' => ['array:unit_id,label'],
            'ad_units.*.*.*.unit_id' => ['required', 'string', 'regex:/^ca-app-pub-\d{16}\/\d{10}$/'],
            'ad_units.*.*.*.label' => ['nullable', 'string', 'max:60'],

            // ─── Per-placement rules ─────────────────────────────────────────
            'ad_rules' => ['sometimes', 'array:interstitial,rewarded,app_open,banner'],
            'ad_rules.interstitial' => ['sometimes', 'array:first_after_episodes,max_per_session,max_per_day'],
            'ad_rules.interstitial.first_after_episodes' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'ad_rules.interstitial.max_per_session' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'ad_rules.interstitial.max_per_day' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'ad_rules.rewarded' => ['sometimes', 'array:max_per_day'],
            'ad_rules.rewarded.max_per_day' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'ad_rules.app_open' => ['sometimes', 'array:enabled'],
            'ad_rules.app_open.enabled' => ['sometimes', 'boolean'],
            'ad_rules.banner' => ['sometimes', 'array:enabled'],
            'ad_rules.banner.enabled' => ['sometimes', 'boolean'],

            // ─── Release policy ──────────────────────────────────────────────
            'app_version' => ['sometimes', 'array:min_supported,latest,update_url_android,update_url_ios,update_message'],
            'app_version.min_supported' => ['required_with:app_version', 'string', 'regex:'.self::VERSION],
            'app_version.latest' => ['required_with:app_version', 'string', 'regex:'.self::VERSION],
            'app_version.update_url_android' => ['nullable', 'url:https', 'max:255'],
            'app_version.update_url_ios' => ['nullable', 'url:https', 'max:255'],
            'app_version.update_message' => ['nullable', 'string', 'max:255'],

            'maintenance' => ['sometimes', 'array:enabled,message'],
            'maintenance.enabled' => ['required_with:maintenance', 'boolean'],
            'maintenance.message' => ['nullable', 'string', 'max:255'],

            // ─── A/B experiment ──────────────────────────────────────────────
            'ad_experiment' => ['sometimes', 'array:enabled,name,variants'],
            'ad_experiment.enabled' => ['required_with:ad_experiment', 'boolean'],
            'ad_experiment.name' => ['required_with:ad_experiment', 'string', 'alpha_dash', 'max:32'],
            'ad_experiment.variants' => ['required_with:ad_experiment', 'array', 'min:2', 'max:4'],
            'ad_experiment.variants.*' => ['array:key,weight,overrides'],
            'ad_experiment.variants.*.key' => ['required', 'string', 'alpha_dash', 'max:16', 'distinct'],
            'ad_experiment.variants.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            'ad_experiment.variants.*.overrides' => ['nullable', 'array:'.implode(',', AppConfigService::OVERRIDABLE)],
            'ad_experiment.variants.*.overrides.is_ads_enabled' => ['sometimes', 'boolean'],
            'ad_experiment.variants.*.overrides.ad_interval_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'ad_experiment.variants.*.overrides.ad_load_timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'ad_experiment.variants.*.overrides.ad_units' => ['sometimes', 'array:'.$platforms],
            'ad_experiment.variants.*.overrides.ad_units.*.*.*.unit_id' => ['required', 'string', 'regex:/^ca-app-pub-\d{16}\/\d{10}$/'],
            'ad_experiment.variants.*.overrides.ad_rules' => ['sometimes', 'array'],
        ];
    }

    /**
     * After the field rules: the minimum version may not be above the latest.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator): void {
                $min = $this->input('app_version.min_supported');
                $latest = $this->input('app_version.latest');

                if (is_string($min) && is_string($latest) && version_compare($min, $latest, '>')) {
                    $validator->errors()->add('app_version.min_supported', 'The minimum version cannot be newer than the latest version.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ad_units.*.*.*.unit_id.regex' => 'Each ad unit id must look like ca-app-pub-1234567890123456/1234567890.',
        ];
    }
}
