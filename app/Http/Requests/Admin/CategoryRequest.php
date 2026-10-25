<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating a catalogue category.
 *
 * One class covers both verbs: `name` is required on create and optional on
 * update, which is the only difference between the two rule sets. Keeping them
 * together means the slug-uniqueness rule cannot drift between store and update.
 */
class CategoryRequest extends FormRequest
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
        $categoryId = $this->route('category')?->id;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],

            'slug' => [
                'sometimes', 'nullable', 'string', 'max:120', 'alpha_dash',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cover_image' => ['sometimes', 'nullable', 'string', 'max:2048'],

            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validated attributes, with the slug derived from the name when omitted.
     *
     * @return array<string, mixed>
     */
    public function categoryAttributes(): array
    {
        $attributes = $this->safe()->only([
            'name', 'slug', 'description', 'cover_image', 'sort_order', 'is_active',
        ]);

        if (blank($attributes['slug'] ?? null) && filled($attributes['name'] ?? null)) {
            $attributes['slug'] = Str::slug($attributes['name']);
        }

        return array_filter($attributes, static fn ($value) => $value !== null);
    }
}
