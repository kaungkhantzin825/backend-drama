<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable points ledger.
     *
     * Every balance change in the app is one row here. Rows are never updated
     * or deleted: a mistake is corrected with a compensating `admin_adjust`
     * entry. `balance_after` is stored so a statement can be rendered without
     * replaying history, and `idempotency_key` (unique) makes a retried request
     * from a flaky mobile connection safe.
     */
    public function up(): void
    {
        Schema::create('points_ledger', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'ad_reward' | 'check_in' | 'episode_unlock' | 'withdrawal' | ...
            $table->string('type', 32);

            // Signed: positive credits, negative debits.
            $table->integer('delta');

            // Balance immediately after this entry — the running total.
            $table->integer('balance_after');

            $table->string('description')->nullable();

            // Optional pointer at the thing that caused the entry.
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->json('meta')->nullable();

            // Prevents double-crediting a retried request.
            $table->string('idempotency_key', 128)->nullable()->unique();

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_ledger');
    }
};
