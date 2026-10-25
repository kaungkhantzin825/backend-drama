<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ad impressions reported by the client.
     *
     * The client never mints points: it reports an impression, and the server
     * decides whether it counts. `idempotency_key` stops the same impression
     * from being replayed, and the device/user columns let the fraud checks in
     * WalletService spot emulators and multi-account farming.
     */
    public function up(): void
    {
        Schema::create('ad_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_id', 128)->nullable();

            // Where in the app the ad was shown: 'rewarded_video', 'interstitial', ...
            $table->string('placement', 32);
            $table->string('provider', 32)->nullable();

            $table->unsignedInteger('reward_points')->default(0);

            // 'pending' | 'verified' | 'rejected'
            $table->string('status', 16)->default('pending');

            $table->string('idempotency_key', 128)->unique();
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['placement', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_events');
    }
};
