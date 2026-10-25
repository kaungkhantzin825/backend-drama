<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which episodes a user has paid to unlock.
     *
     * A separate table (rather than a flag on the episode) because the same
     * episode is locked for one viewer and unlocked for another. The unique
     * pair is what makes unlocking idempotent: paying twice for the same
     * episode is impossible at the database level.
     */
    public function up(): void
    {
        Schema::create('episode_unlocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();

            // What it actually cost at the time of purchase.
            $table->unsignedInteger('points_spent')->default(0);

            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'episode_id']);
            $table->index(['episode_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_unlocks');
    }
};
