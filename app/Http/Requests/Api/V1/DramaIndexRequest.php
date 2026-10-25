<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `GET /api/v1/dramas`.
 *
 * Filter values are whitelisted rather than passed through: `sort` in
 * particular reaches an ORDER BY clause, so anything unrecognised must be
 * rejected before it gets near the query builder.
 */
class DramaIndexRequest extends FormRequest
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
            // Category slug (preferred, SEO friendly) or numeric id.
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],

            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],

            'featured' => ['sometimes', 'boolean'],

            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['latest', 'popular', 'rating', 'title'])],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],

            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.min' => 'Enter at least 2 characters to search.',
            'sort.in' => 'Sort must be one of: latest, popular, rating, title.',
        ];
    }

    /**
     * Normalised filters for the repository.
     *
     * @return array{category: string|null, q: string|null, featured: bool|null, sort: string|null, per_page: int|null}
     */
    public function filters(): array
    {
        return [
            'category' => $this->filled('category') ? $this->string('category')->toString() : null,
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
            'featured' => $this->has('featured') ? $this->boolean('featured') : null,
            'sort' => $this->filled('sort') ? $this->string('sort')->toString() : null,
            'per_page' => $this->has('per_page') ? $this->integer('per_page') : null,
        ];
    }
}
