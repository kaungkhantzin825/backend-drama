<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue category / shelf.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'sort_order' => (int) $this->sort_order,
            // Present only when the query counted it (`withCount`).
            'drama_count' => $this->whenCounted('dramas'),
        ];
    }
}
