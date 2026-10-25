<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A genre / shelf in the catalogue.
 *
 * Flat by design — the mobile home screen renders categories as chips, and a
 * drama belongs to at most one category.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $cover_image
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Column defaults mirrored on the model.
     *
     * The database supplies these too, but a freshly created model would
     * otherwise report `is_active = null` (i.e. falsy) until it is reloaded —
     * so the very response that created the category would claim it is hidden.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_image',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Every drama shelved under this category.
     */
    public function dramas(): HasMany
    {
        return $this->hasMany(Drama::class);
    }

    /**
     * Only the dramas the mobile client is allowed to see.
     */
    public function publishedDramas(): HasMany
    {
        return $this->dramas()->published();
    }

    // ─── Query scopes ─────────────────────────────────────────────────────────

    /**
     * Categories the mobile client may list, in display order.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
