<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(12),
            'cover_image' => 'https://cdn.dramaloop.test/categories/'.Str::slug($name).'.jpg',
            'sort_order' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * A hidden category — still in the database, invisible to the app.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
