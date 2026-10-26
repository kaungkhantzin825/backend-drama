<?php

namespace App\Http\Requests\Admin;

use App\Services\RewardSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for `PUT /admin/api/reward-settings`.
 *
 * Each section is optional, so the screen can save one card at a time; a
 * section that is sent must be complete.
 */
class RewardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is enforced by the `admin.role` middleware on the route.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'watch_reward' => ['sometimes', 'array'],
            'watch_reward.enabled' => ['required_with:watch_reward', 'boolean'],
            'watch_reward.points' => ['required_with:watch_reward', 'integer', 'min:1', 'max:100000'],
            'watch_reward.interval_seconds' => ['required_with:watch_reward', 'integer', 'min:10', 'max:3600'],
            'watch_reward.daily_cap' => ['required_with:watch_reward', 'integer', 'min:0', 'max:10000000'],

            'check_in' => ['sometimes', 'array'],
            'check_in.enabled' => ['required_with:check_in', 'boolean'],
            'check_in.points' => ['required_with:check_in', 'integer', 'min:0', 'max:100000'],

            'ad_reward' => ['sometimes', 'array'],
            'ad_reward.enabled' => ['required_with:ad_reward', 'boolean'],
            'ad_reward.points' => ['required_with:ad_reward', 'integer', 'min:0', 'max:100000'],
            'ad_reward.daily_limit' => ['required_with:ad_reward', 'integer', 'min:0', 'max:1000'],

            'cash_out' => ['sometimes', 'array'],
            'cash_out.currency' => ['required_with:cash_out', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'cash_out.points_per_unit' => ['required_with:cash_out', 'integer', 'min:1', 'max:10000000'],
            'cash_out.min_points' => ['required_with:cash_out', 'integer', 'min:0', 'max:100000000'],
            'cash_out.daily_limit' => ['required_with:cash_out', 'integer', 'min:1', 'max:100'],

            'lucky_wheel' => ['sometimes', 'array'],
            'lucky_wheel.enabled' => ['required_with:lucky_wheel', 'boolean'],
            'lucky_wheel.daily_spins' => ['required_with:lucky_wheel', 'integer', 'min:0', 'max:100'],
            'lucky_wheel.prizes' => ['required_with:lucky_wheel', 'array', 'min:2', 'max:'.RewardSettings::MAX_PRIZES],
            'lucky_wheel.prizes.*.points' => ['required', 'integer', 'min:0', 'max:100000'],
            'lucky_wheel.prizes.*.weight' => ['required', 'integer', 'min:1', 'max:100000'],

            'payout_methods' => ['sometimes', 'array', 'max:20'],
            'payout_methods.*.code' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'payout_methods.*.name' => ['required', 'string', 'max:40'],
            'payout_methods.*.account_label' => ['required', 'string', 'max:60'],
            'payout_methods.*.account_hint' => ['nullable', 'string', 'max:60'],
            'payout_methods.*.input' => ['required', 'string', Rule::in(RewardSettings::INPUT_TYPES)],
            'payout_methods.*.enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_out.currency.regex' => 'The currency must be a 3-letter code such as MMK or USD.',
            'payout_methods.*.code.regex' => 'Method codes may only use a–z, 0–9 and _.',
            'payout_methods.*.code.distinct' => 'Each payment method needs its own code.',
            'lucky_wheel.prizes.min' => 'The wheel needs at least 2 prizes.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $methods = $this->input('payout_methods');

                if (is_array($methods) && ! collect($methods)->contains(fn ($m): bool => (bool) ($m['enabled'] ?? false))) {
                    $validator->errors()->add('payout_methods', 'Keep at least one payment method switched on.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->safe()->only(RewardSettings::SECTIONS);
    }
}
