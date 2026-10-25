<?php

namespace App\Http\Requests\Admin;

use App\Enums\DramaStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating a drama.
 *
 * One class covers both verbs so the slug-uniqueness and status-enum rules
 * cannot drift between store and update.
 */
class DramaRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $dramaId = $this->route('drama')?->id;

        return [
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],

            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],

            'slug' => [
                'sometimes', 'nullable', 'string', 'max:200', 'alpha_dash',
                Rule::unique('dramas', 'slug')->ignore($dramaId),
            ],

            'synopsis' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'cover_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'banner_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'trailer_url' => ['sometimes', 'nullable', 'string', 'max:2048'],

            // HLS/DASH manifest, MP4 or YouTube URL.
            'video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:7200'],

            'language' => ['sometimes', 'string', 'min:2', 'max:16'],
            'release_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],

            'status' => ['sometimes', 'string', Rule::in(DramaStatus::values())],
            'is_featured' => ['sometimes', 'boolean'],

            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:32'],

            'published_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Validated attributes for the service layer.
     *
     * `view_count`, `like_count` and `rating` are deliberately not fillable
     * from the request: they are counters maintained by the application, and
     * letting an editor set them would corrupt the ranking.
     *
     * @return array<string, mixed>
     */
    public function dramaAttributes(): array
    {
        $attributes = $this->safe()->only([
            'category_id', 'title', 'slug', 'synopsis',
            'cover_image', 'banner_image', 'trailer_url',
            'video_url', 'duration_seconds',
            'language', 'release_year', 'status', 'is_featured',
            'tags', 'published_at',
        ]);

        // Keep `false` and `0`; only drop genuine nulls so an explicit
        // `is_featured: false` actually un-features the drama.
        return array_filter($attributes, static fn ($value) => $value !== null);
    }
}
