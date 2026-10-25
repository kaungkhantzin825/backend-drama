<?php

namespace App\Models;

use App\Enums\DramaStatus;
use Database\Factories\DramaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One standalone vertical video.
 *
 * The app plays these in a TikTok-style feed; there are no episodes. Mobile
 * visibility is governed by `status` plus `published_at`: a video only
 * appears in the public API when it is published *and* its publish timestamp
 * is not in the future.
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $synopsis
 * @property string|null $cover_image
 * @property string|null $banner_image
 * @property string|null $trailer_url
 * @property string|null $video_url
 * @property int $duration_seconds
 * @property string $language
 * @property int|null $release_year
 * @property DramaStatus $status
 * @property bool $is_featured
 * @property int $view_count
 * @property int $like_count
 * @property string|null $rating
 * @property array<int, string>|null $tags
 * @property Carbon|null $published_at
 */
class Drama extends Model
{
    /** @use HasFactory<DramaFactory> */
    use HasFactory;

    /**
     * Column defaults mirrored on the model.
     *
     * The database supplies these too, but a freshly created model would
     * otherwise hold `null` for `status` until it is reloaded — which breaks
     * the `DramaStatus` cast (and anything reading `$drama->status->value`)
     * on the very response that created the row.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'language' => 'en',
        'status' => 'draft',
        'duration_seconds' => 0,
        'is_featured' => false,
        'view_count' => 0,
        'like_count' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'synopsis',
        'cover_image',
        'banner_image',
        'trailer_url',
        'video_url',
        'duration_seconds',
        'language',
        'release_year',
        'status',
        'is_featured',
        'view_count',
        'like_count',
        'rating',
        'tags',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DramaStatus::class,
            'is_featured' => 'boolean',
            'release_year' => 'integer',
            'duration_seconds' => 'integer',
            'view_count' => 'integer',
            'like_count' => 'integer',
            'rating' => 'decimal:2',
            'tags' => 'array',
            'published_at' => 'datetime',
        ];
    }

    // ─── Boot ─────────────────────────────────────────────────────────────────

    /**
     * Keep `slug` in step with `title` when an editor does not supply one.
     */
    protected static function booted(): void
    {
        static::saving(function (Drama $drama): void {
            if (blank($drama->slug)) {
                $drama->slug = static::uniqueSlug($drama->title, $drama->id);
            }

            // Publishing without an explicit timestamp stamps "now", so the
            // mobile list ordering has something to sort on.
            if ($drama->status === DramaStatus::Published && $drama->published_at === null) {
                $drama->published_at = now();
            }
        });
    }

    /**
     * Build a slug that does not collide with an existing drama.
     */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'drama';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // ─── Query scopes ─────────────────────────────────────────────────────────

    /**
     * Dramas visible to the mobile client.
     *
     * @param  Builder<Drama>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', DramaStatus::Published->value)
            ->where(function (Builder $inner): void {
                $inner->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /**
     * @param  Builder<Drama>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * @param  Builder<Drama>  $query
     */
    public function scopeInCategory(Builder $query, int|string $category): void
    {
        if (is_numeric($category)) {
            $query->where('category_id', (int) $category);

            return;
        }

        $query->whereHas('category', fn (Builder $inner) => $inner->where('slug', $category));
    }

    /**
     * Free-text search over title, synopsis and tags.
     *
     * @param  Builder<Drama>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('title', 'like', $like)
                ->orWhere('synopsis', 'like', $like)
                ->orWhere('tags', 'like', $like);
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * True when the drama is live for mobile users right now.
     */
    public function isVisible(): bool
    {
        return $this->status === DramaStatus::Published
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /**
     * Human friendly duration, e.g. "1:47".
     */
    public function durationLabel(): string
    {
        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
