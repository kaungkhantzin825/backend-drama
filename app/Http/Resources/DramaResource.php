<?php

namespace App\Http\Resources;

use App\Models\Drama;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A video as it appears in a list / card and in the swipe feed.
 *
 * Carries the stream URL — every video is free to watch, and the feed pages
 * through this list directly — but not the full synopsis body, so a 20-item
 * page stays small on mobile data. The detail endpoint returns
 * {@see DramaDetailResource} instead.
 *
 * @mixin Drama
 */
class DramaResource extends JsonResource
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
            'tagline' => $this->excerpt(),
            'cover_image' => $this->cover_image,
            'banner_image' => $this->banner_image,
            'video_url' => $this->video_url,
            'duration_seconds' => (int) $this->duration_seconds,
            'duration_label' => $this->durationLabel(),
            'language' => $this->language,
            'release_year' => $this->release_year,
            'is_featured' => (bool) $this->is_featured,
            'view_count' => (int) $this->view_count,
            'like_count' => (int) $this->like_count,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'tags' => $this->tags ?? [],
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /**
     * A one-line teaser for cards — the synopsis trimmed to a sane length.
     */
    private function excerpt(): ?string
    {
        if (blank($this->synopsis)) {
            return null;
        }

        return str($this->synopsis)->limit(120)->toString();
    }
}
