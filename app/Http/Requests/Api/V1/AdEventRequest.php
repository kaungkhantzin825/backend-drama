<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `POST /api/v1/ads/events`.
 *
 * `idempotency_key` is required: it is the only thing stopping a retried or
 * replayed request from minting points twice.
 */
class AdEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'placement' => ['required', 'string', 'in:rewarded_video,interstitial,banner,native'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'An idempotency key is required.',
            'placement.in' => 'Unknown ad placement.',
        ];
    }
}
