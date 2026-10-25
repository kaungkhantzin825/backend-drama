<?php

namespace App\Http\Resources\Admin;

use App\Models\Drama;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A drama as the admin panel sees it.
 *
 * Exposes publication state and the raw counters — everything an editor needs
 * to decide whether a title is live, and everything the mobile payload omits.
 *
 * @mixin Drama
 */
class DramaAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'cover_image' => $this->cover_image,
            'banner_image' => $this->banner_image,
            'trailer_url' => $this->trailer_url,
            'video_url' => $this->video_url,
            'duration_seconds' => (int) $this->duration_seconds,
            'duration_label' => $this->durationLabel(),
            'language' => $this->language,
            'release_year' => $this->release_year,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_featured' => (bool) $this->is_featured,
            'is_visible' => $this->isVisible(),
            'view_count' => (int) $this->view_count,
            'like_count' => (int) $this->like_count,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'tags' => $this->tags ?? [],
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
