<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One episode of a drama.
     *
     * Episodes are short (60-180s) and watched back-to-back, so the list is
     * always ordered by `episode_number` and the app prefetches the next one.
     *
     * Monetisation lives here, not on the drama:
     *  - `is_free`            → the first few episodes are free to hook the viewer.
     *  - `unlock_cost_points` → how many reward points the next episode costs.
     *
     * The actual unlock decision is made server-side in Phase 3 against the
     * immutable points ledger; this table only stores the price. The client is
     * never trusted to decide whether an episode is unlocked.
     */
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();

            // 1-based position inside the drama.
            $table->unsignedInteger('episode_number');

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // HLS/DASH manifest or MP4 URL served by the CDN.
            $table->string('video_url')->nullable();
            $table->string('thumbnail_url')->nullable();

            // Duration in seconds — used for progress bars and the "next up" rail.
            $table->unsignedInteger('duration_seconds')->default(0);

            // Free episodes need no unlock; the rest cost points.
            $table->boolean('is_free')->default(true);
            $table->unsignedInteger('unlock_cost_points')->default(0);

            $table->unsignedBigInteger('view_count')->default(0);

            // Scheduled releases: an episode with a future published_at must not
            // be returned by the public API yet.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // An episode number is unique within its drama.
            $table->unique(['drama_id', 'episode_number']);
            // Ordered listing / next-episode lookups.
            $table->index(['drama_id', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
