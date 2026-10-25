<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Genres / shelves shown on the mobile home screen.
     *
     * Categories are a flat list on purpose: short-drama catalogues are browsed
     * by tapping a chip ("Romance", "Revenge", "CEO"), never by drilling into a
     * tree. `sort_order` controls the chip order and `is_active` lets an editor
     * hide a shelf without deleting the dramas inside it.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();

            // Lower numbers are shown first.
            $table->unsignedInteger('sort_order')->default(0);

            // Hidden categories stay in the database but never reach the app.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
