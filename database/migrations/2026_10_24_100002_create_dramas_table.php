<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A drama — one vertical short-form series.
     *
     * The product model is "series → episodes": a drama owns an ordered run of
     * 1-3 minute vertical episodes, and the viewer unlocks them one at a time.
     * `total_episodes` is a denormalised counter maintained by ContentService so
     * list screens never have to run a COUNT() per card.
     *
     * `status` gates mobile visibility: only `published` rows are ever returned
     * by the public API. `published_at` is separate from `created_at` so an
     * editor can schedule a release without backdating the record.
     */
    public function up(): void
    {
        Schema::create('dramas', function (Blueprint $table) {
            $table->id();

            // Nullable so a drama can exist before it is shelved.
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('synopsis')->nullable();

            // Portrait poster (2:3) for cards, landscape hero (16:9) for detail.
            $table->string('cover_image')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('trailer_url')->nullable();

            $table->string('language', 16)->default('en');
            $table->unsignedSmallInteger('release_year')->nullable();

            // Denormalised episode count, kept in sync by ContentService.
            $table->unsignedInteger('total_episodes')->default(0);

            // 'draft' | 'published' | 'archived'
            $table->string('status', 16)->default('draft');

            // Powers the "Featured" rail on the home screen.
            $table->boolean('is_featured')->default(false);

            // Denormalised engagement counters. `view_count` is incremented by
            // ContentService; `like_count` and `rating` are maintained in Phase 4
            // alongside the likes/reviews tables.
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('like_count')->default(0);
            $table->decimal('rating', 3, 2)->nullable();

            // Free-form tags for search and recommendations ("ceo", "revenge").
            $table->json('tags')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Mobile browse: published list ordered by publish date.
            $table->index(['status', 'published_at']);
            // Category shelves.
            $table->index(['category_id', 'status']);
            // Featured rail.
            $table->index(['is_featured', 'status']);
            // "Most popular" sort.
            $table->index('view_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dramas');
    }
};
