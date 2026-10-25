<?php

namespace Database\Factories;

use App\Enums\DramaStatus;
use App\Models\Category;
use App\Models\Drama;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drama>
 */
class DramaFactory extends Factory
{
    protected $model = Drama::class;

    /**
     * `slug` is deliberately omitted: Drama::booted() derives a guaranteed-unique
     * slug from the title, which is exactly what the admin panel relies on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'title' => fake()->unique()->sentence(3),
            'synopsis' => fake()->paragraph(4),
            'cover_image' => 'https://cdn.dramaloop.test/dramas/cover-'.fake()->unique()->numberBetween(1000, 99999).'.jpg',
            'banner_image' => 'https://cdn.dramaloop.test/dramas/banner-'.fake()->unique()->numberBetween(1000, 99999).'.jpg',
            'trailer_url' => 'https://cdn.dramaloop.test/trailers/'.fake()->unique()->numberBetween(1000, 99999).'.m3u8',
            'video_url' => 'https://cdn.dramaloop.test/videos/'.fake()->unique()->numberBetween(1000, 999999).'.m3u8',
            'duration_seconds' => fake()->numberBetween(60, 180),
            'language' => 'en',
            'release_year' => fake()->numberBetween(2022, 2026),
            'status' => DramaStatus::Published,
            'is_featured' => false,
            'view_count' => fake()->numberBetween(0, 500000),
            'like_count' => fake()->numberBetween(0, 50000),
            'rating' => fake()->randomFloat(2, 3.0, 5.0),
            'tags' => fake()->randomElements(
                ['ceo', 'revenge', 'romance', 'billionaire', 'rebirth', 'contract-marriage', 'hidden-identity'],
                fake()->numberBetween(1, 3),
            ),
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * An unpublished draft — must never appear in the mobile catalogue.
     */
    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => DramaStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * An archived title — also invisible to the app.
     */
    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => DramaStatus::Archived]);
    }

    /**
     * A published drama with a future release date — not yet visible.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => DramaStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    /**
     * Showcase this drama in the home screen's featured rail.
     */
    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    /**
     * Attach the drama to a specific category.
     */
    public function inCategory(Category $category): static
    {
        return $this->state(fn (): array => ['category_id' => $category->id]);
    }
}
