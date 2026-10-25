<?php

namespace App\Repositories;

use App\Models\Drama;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Data-access for dramas.
 *
 * Every public-facing read goes through `published()` so a draft or archived
 * title can never leak into the mobile catalogue by accident — the visibility
 * rule lives in exactly one place.
 */
class DramaRepository
{
    /**
     * Paginated catalogue for the mobile browse screen.
     *
     * @param  array{
     *     category?: string|null,
     *     q?: string|null,
     *     featured?: bool|null,
     *     sort?: string|null,
     *     per_page?: int|null
     * }  $filters
     */
    public function browse(array $filters = []): LengthAwarePaginator
    {
        $perPage = $this->clampPerPage($filters['per_page'] ?? null);

        return Drama::query()
            ->published()
            ->with('category')
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->inCategory($category))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->search($term))
            ->when($filters['featured'] ?? null, fn (Builder $query) => $query->featured())
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? null))
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The "featured" rail on the home screen.
     *
     * @return Collection<int, Drama>
     */
    public function featured(int $limit = 10): Collection
    {
        return Drama::query()
            ->published()
            ->featured()
            ->with('category')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The newest published dramas.
     *
     * @return Collection<int, Drama>
     */
    public function latest(int $limit = 10): Collection
    {
        return Drama::query()
            ->published()
            ->with('category')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The most watched published dramas.
     *
     * @return Collection<int, Drama>
     */
    public function trending(int $limit = 10): Collection
    {
        return Drama::query()
            ->published()
            ->with('category')
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }

    /**
     * A published drama with its category.
     */
    public function findPublishedBySlug(string $slug): ?Drama
    {
        return Drama::query()
            ->published()
            ->with('category')
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Any drama by id, regardless of status — admin panel only.
     */
    public function find(int $id): ?Drama
    {
        return Drama::query()->find($id);
    }

    /**
     * Paginated admin listing with optional status / category / search filters.
     *
     * @param  array{q?: string|null, status?: string|null, category_id?: int|null, per_page?: int|null}  $filters
     */
    public function adminSearch(array $filters = []): LengthAwarePaginator
    {
        $perPage = $this->clampPerPage($filters['per_page'] ?? null);

        return Drama::query()
            ->with('category')
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->search($term))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Drama
    {
        return Drama::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Drama $drama, array $attributes): Drama
    {
        $drama->fill($attributes)->save();

        return $drama->refresh();
    }

    public function delete(Drama $drama): void
    {
        $drama->delete();
    }

    /**
     * Record one view without touching `updated_at`.
     */
    public function incrementViewCount(Drama $drama): void
    {
        Drama::query()->whereKey($drama->getKey())->increment('view_count');
    }

    /**
     * Sorting whitelist. Anything unrecognised falls back to "latest" so a
     * crafted `?sort=` value can never produce an invalid ORDER BY.
     *
     * @param  Builder<Drama>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('view_count')->orderByDesc('id'),
            'rating' => $query->orderByDesc('rating')->orderByDesc('id'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Keep page sizes sane so a client cannot ask for the whole table.
     */
    private function clampPerPage(?int $perPage): int
    {
        return max(1, min($perPage ?? 20, 50));
    }
}
