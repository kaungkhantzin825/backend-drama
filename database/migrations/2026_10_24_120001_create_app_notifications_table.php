<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-app notification inbox.
     *
     * Push delivery is best-effort: a notification is persisted here first, and
     * the queued FCM job is a separate, retryable step. That way a user still
     * sees "your withdrawal was paid" even if Google is unreachable or the
     * device has no token registered.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'withdrawal_paid' | 'reward_granted' | ... — see NotificationType.
            $table->string('type', 40);

            $table->string('title');
            $table->text('body')->nullable();

            // Deep-link payload for the client: { route, drama_slug, ... }.
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();

            // Set once FCM accepted the message, so a retry does not push twice.
            $table->timestamp('pushed_at')->nullable();

            $table->timestamps();

            // The inbox query: this user's unread, newest first.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
