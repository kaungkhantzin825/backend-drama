<?php

namespace App\Services;

use App\Enums\DramaStatus;
use App\Models\Category;
use App\Models\Drama;
use App\Repositories\CategoryRepository;
use App\Repositories\DramaRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Catalogue business logic.
 *
 * Everything the mobile app and the admin panel do to videos and categories
 * funnels through here so the two surfaces cannot drift apart: both go
 * through the same visibility rules and the same slug generation.
 */
class ContentService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly DramaRepository $dramas,
    ) {}

    // ─── Mobile: browse ───────────────────────────────────────────────────────

    /**
     * Everything the home screen needs, in one round trip.
     *
     * Short-drama apps are consumed in a "scroll the rails" pattern, so the
     * home payload is three curated rails plus the category chips rather than
     * a single paginated list.
     *
     * @return array{
     *     featured: Collection<int, Drama>,
     *     latest: Collection<int, Drama>,
     *     trending: Collection<int, Drama>,
     *     categories: Collection<int, Category>
     * }
     */
    public function home(): array
    {
        return [
            'featured' => $this->dramas->featured(10),
            'latest' => $this->dramas->latest(10),
            'trending' => $this->dramas->trending(10),
            'categories' => $this->categories->active(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function browseDramas(array $filters = []): LengthAwarePaginator
    {
        return $this->dramas->browse($filters);
    }

    /**
     * A published video plus a view-count bump.
     *
     * The app fetches this when a video comes on screen in the feed, so it is
     * also where views are counted. The bump is fire-and-forget: a failure to
     * record a view must never stop the user from watching.
     */
    public function dramaDetail(string $slug): ?Drama
    {
        $drama = $this->dramas->findPublishedBySlug($slug);

        if ($drama === null) {
            return null;
        }

        rescue(fn () => $this->dramas->incrementViewCount($drama), report: false);

        return $drama;
    }

    // ─── Admin: categories ────────────────────────────────────────────────────

    /**
     * @return Collection<int, Category>
     */
    public function allCategories(): Collection
    {
        return $this->categories->all();
    }

    /**
     * Active categories with a published-drama count per shelf.
     *
     * @return Collection<int, Category>
     */
    public function activeCategoriesWithCounts(): Collection
    {
        return $this->categories->activeWithPublishedDramaCount();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCategory(array $attributes): Category
    {
        return $this->categories->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCategory(Category $category, array $attributes): Category
    {
        return $this->categories->update($category, $attributes);
    }

    /**
     * Deleting a category leaves its dramas in place (`nullOnDelete`), so no
     * catalogue content is lost by removing a shelf.
     */
    public function deleteCategory(Category $category): void
    {
        $this->categories->delete($category);
    }

    // ─── Admin: videos ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filters
     */
    public function adminDramas(array $filters = []): LengthAwarePaginator
    {
        return $this->dramas->adminSearch($filters);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDrama(array $attributes): Drama
    {
        return $this->dramas->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDrama(Drama $drama, array $attributes): Drama
    {
        return $this->dramas->update($drama, $attributes);
    }

    public function deleteDrama(Drama $drama): void
    {
        // Watch progress and favourites go with it via `cascadeOnDelete`.
        $this->dramas->delete($drama);
    }

    /**
     * Publish a drama, stamping `published_at` if it has never been set.
     */
    public function publishDrama(Drama $drama): Drama
    {
        return $this->dramas->update($drama, [
            'status' => DramaStatus::Published->value,
            'published_at' => $drama->published_at ?? now(),
        ]);
    }
}
