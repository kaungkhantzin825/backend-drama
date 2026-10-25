<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /api/v1/withdrawals`.
 *
 * The payout destination is required per method, because an approved request
 * with no PayPal email is unpayable and would block the admin queue.
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
        $methods = config('rewards.payout_methods', []);

        return [
            'method' => ['required', 'string', Rule::in($methods)],
            'account_details' => ['required', 'array'],

            // PayPal needs an email; bank needs an account name + number.
            'account_details.email' => ['required_if:method,paypal', 'nullable', 'email', 'max:190'],
            'account_details.account_name' => ['required_if:method,bank', 'nullable', 'string', 'max:190'],
            'account_details.account_number' => ['required_if:method,bank', 'nullable', 'string', 'max:64'],
            'account_details.note' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, mixed> */
    public function accountDetails(): array
    {
        return (array) $this->input('account_details', []);
    }
}
