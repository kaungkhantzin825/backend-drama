<?php

namespace App\Http\Requests\Api\V1;

use App\Services\AppConfigService;
use App\Services\TrafficRecorder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of ad SDK events from the app's AdTrafficReporter.
 */
class AdTrafficRequest extends FormRequest
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
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event' => ['required', 'string', Rule::in(array_keys(TrafficRecorder::AD_EVENTS))],
            'events.*.format' => ['required', 'string', Rule::in([...AppConfigService::FORMATS, 'app_open', 'native'])],
            'events.*.placement' => ['nullable', 'string', 'max:32'],
            'events.*.unit_id' => ['nullable', 'string', 'max:64'],
            'events.*.tier' => ['nullable', 'integer', 'min:1', 'max:10'],
            // One impression's revenue, in micros. Capped to keep a tampered
            // client from inflating the revenue report absurdly.
            'events.*.value_micros' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
