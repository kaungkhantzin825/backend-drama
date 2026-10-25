<?php

namespace App\Http\Resources\Admin;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category as the admin panel sees it — includes the fields the mobile
 * payload deliberately hides (`is_active`, timestamps).
 *
 * @mixin Category
 */
class CategoryAdminResource extends JsonResource
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
            'is_active' => (bool) $this->is_active,
            'drama_count' => $this->whenCounted('dramas'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
