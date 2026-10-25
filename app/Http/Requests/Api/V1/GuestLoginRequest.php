<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /api/v1/auth/guest`.
 *
 * The device id is required — it is the anchor for guest accounts and the
 * first line of anti-fraud defence.
 */
class GuestLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'min:8', 'max:128'],
            'platform' => ['sometimes', 'string', Rule::in(Platform::values())],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'fcm_token' => ['sometimes', 'nullable', 'string', 'max:512'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_emulator' => ['sometimes', 'boolean'],
            'is_rooted' => ['sometimes', 'nullable', 'boolean'],
            'device_hash' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_id.required' => 'A device id is required to sign in.',
            'device_id.min' => 'The device id is not valid.',
        ];
    }

    /**
     * Device attributes, normalised for the service layer.
     *
     * @return array<string, mixed>
     */
    public function deviceAttributes(): array
    {
        return array_filter([
            'device_id' => $this->string('device_id')->toString(),
            'platform' => $this->input('platform', Platform::Android->value),
            'app_version' => $this->input('app_version'),
            'fcm_token' => $this->input('fcm_token'),
            'model' => $this->input('model'),
            'os_version' => $this->input('os_version'),
            'is_emulator' => (bool) $this->boolean('is_emulator'),
            'is_rooted' => $this->has('is_rooted') ? $this->boolean('is_rooted') : null,
            'device_hash' => $this->input('device_hash'),
        ], fn ($value) => $value !== null);
    }
}
