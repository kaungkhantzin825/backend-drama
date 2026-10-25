<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lucky-wheel spins.
     *
     * One row per spin is what enforces the daily limit — counting rows is
     * cheaper and more trustworthy than trusting a client-reported counter.
     * The payout itself still goes through the ledger like every other reward.
     */
    public function up(): void
    {
        Schema::create('lucky_wheel_spins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('prize_points');
            $table->unsignedTinyInteger('segment_index')->default(0);

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_wheel_spins');
    }
};
