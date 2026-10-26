<?php

namespace App\Http\Requests\Api\V1;

use App\Services\RewardSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /api/v1/withdrawals`.
 *
 * The method must be one the admin has switched on, and every method needs an
 * account holder name plus its account field (phone, email or number) —
 * an approved request with no destination is unpayable and would block the
 * admin queue.
 */
class WithdrawalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(RewardSettings::class);
        $codes = array_column($settings->payoutMethods(enabledOnly: true), 'code');
        $method = $settings->payoutMethod((string) $this->input('method'));

        return [
            'method' => ['required', 'string', Rule::in($codes)],
            'account_details' => ['required', 'array'],
            'account_details.account_name' => ['required', 'string', 'max:190'],
            'account_details.account_number' => array_merge(
                ['required', 'string', 'max:190'],
                ($method['input'] ?? null) === 'email' ? ['email'] : [],
            ),
            'account_details.note' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.in' => 'Choose one of the available payment methods.',
            'account_details.account_name.required' => 'Enter the account holder name.',
            'account_details.account_number.required' => 'Enter the account to pay into.',
        ];
    }

    /** @return array<string, mixed> */
    public function accountDetails(): array
    {
        return array_intersect_key(
            (array) $this->input('account_details', []),
            array_flip(['account_name', 'account_number', 'note']),
        );
    }
}
