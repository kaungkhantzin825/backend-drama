<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each viewer stopped, per episode.
     *
     * One row per (user, episode) so "continue watching" and the watch history
     * are the same data read two ways — no separate history table to drift out
     * of sync.
     *
     * `credited_intervals` is the anti-fraud anchor for watch-time rewards: the
     * server pays for intervals it has not paid for before, so seeking to the
     * end of an episode earns nothing extra.
     */
    public function up(): void
    {
        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();

            // Denormalised so the "continue watching" rail does not need a join
            // through episodes to group by drama.
            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();

            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);

            // How many whole reward intervals have already been paid out.
            $table->unsignedInteger('credited_intervals')->default(0);

            $table->boolean('is_completed')->default(false);

            $table->timestamp('last_watched_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'episode_id']);
            $table->index(['user_id', 'last_watched_at']);
            $table->index(['user_id', 'drama_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_progress');
    }
};
