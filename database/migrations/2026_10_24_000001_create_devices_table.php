<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices are the primary anti-fraud anchor for this app.
     *
     * A reward app is attacked by: (a) emulators running scripts, (b) users
     * creating many accounts on one device, (c) shared device-id spoofing.
     * By recording every device that signs in, along with the account(s) bound
     * to it, we can flag suspicious patterns in Phase 4's fraud checks and
     * block emulator signatures in Phase 7.
     *
     * `device_id` is the app-generated identifier (not the raw hardware id),
     * supplied by the Flutter client on every login.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            // App-supplied stable identifier for this install.
            $table->string('device_id')->unique();

            // Which accounts have used this device. Used to detect multi-account
            // abuse and to power the "many accounts per device" fraud flag.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Firebase Cloud Messaging token for push notifications.
            $table->string('fcm_token')->nullable()->index();

            // 'android' | 'ios' | 'web'
            $table->string('platform')->default('android')->index();

            $table->string('app_version')->nullable();

            $table->string('model')->nullable();
            $table->string('os_version')->nullable();

            // Device fingerprint flags for anti-fraud (set by the client on
            // registration, verified server-side where possible).
            $table->boolean('is_emulator')->default(false);
            $table->boolean('is_rooted')->nullable();
            $table->string('device_hash', 128)->nullable();

            // Request metadata captured at each registration.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
