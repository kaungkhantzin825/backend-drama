<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "My list".
     *
     * A join table rather than a flag on `dramas`, because favourites are
     * per-account and must survive a title being unpublished.
     */
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'drama_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
