<?php

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

/**
 * Data-access for catalogue categories.
 */
class CategoryRepository
{
    /**
     * Categories the mobile client may list, in display order.
     *
     * @return Collection<int, Category>
     */
    public function active(): Collection
    {
        return Category::query()->active()->get();
    }

    /**
     * Active categories with a count of *published* dramas per shelf.
     *
     * Counting only published titles means a shelf never advertises a number
     * the viewer cannot actually open.
     *
     * @return Collection<int, Category>
     */
    public function activeWithPublishedDramaCount(): Collection
    {
        return Category::query()
            ->active()
            ->withCount(['dramas' => fn ($query) => $query->published()])
            ->get();
    }

    /**
     * Every category, including hidden ones — admin panel only.
     *
     * @return Collection<int, Category>
     */
    public function all(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function find(int $id): ?Category
    {
        return Category::query()->find($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return Category::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Category
    {
        return Category::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $category->fill($attributes)->save();

        return $category->refresh();
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
