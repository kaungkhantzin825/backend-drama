<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A playback heartbeat from the player.
 *
 * The client sends where it is; the server decides what that is worth.
 */
class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Upper bound is generous but finite: a 24h position is a bug or an
            // attack, not a short drama.
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            // Playback time since the last heartbeat. Bounded here, and the
            // server still caps what it credits against the wall clock.
            'watched_seconds' => ['sometimes', 'integer', 'min:0', 'max:600'],
        ];
    }
}
