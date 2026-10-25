<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users serve both audiences:
     *  - Mobile app users (`role` = null). These authenticate via Sanctum
     *    tokens and may be guests or Google-linked accounts.
     *  - Admin panel users (`role` = 'admin' | 'editor'). These authenticate
     *    through the separate `admin` session guard.
     *
     * The `role` column being NULL is what separates the two worlds, which
     * keeps a single `users` table without leaking admin accounts to the
     * mobile API.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();

            // Guests have no email. Uniqueness is scoped so two guest accounts
            // (NULL emails) never collide on MySQL's unique index.
            $table->string('email')->nullable()->unique();

            $table->timestamp('email_verified_at')->nullable();

            $table->string('password')->nullable();

            // ─── Google Sign-In ───────────────────────────────────────────────
            // The "sub" claim from the Google ID token. Unique per Google account.
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url')->nullable();

            // ─── Guest / device linkage ──────────────────────────────────────
            // Guest accounts are bound to a device id at creation time so we can
            // detect multi-account abuse later (see DeviceService).
            $table->string('device_id')->nullable()->index();

            // ─── Role (admin panel) ──────────────────────────────────────────
            // null = mobile user, 'admin' | 'editor' = staff.
            $table->string('role')->nullable()->index();

            // ─── Rewards balance ─────────────────────────────────────────────
            // Denormalised cache of the points balance. The authoritative
            // history is `points_ledger`; the balance is only ever changed by
            // the RewardService inside a transaction (Phase 3).
            $table->unsignedBigInteger('points_balance')->default(0);

            // Lifetime aggregates, handy for admin dashboards without scanning
            // the ledger on every page load.
            $table->unsignedBigInteger('total_points_earned')->default(0);
            $table->unsignedBigInteger('total_points_spent')->default(0);

            // Anti-fraud signals.
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            // Moderation. A banned user keeps their data (and ledger) but cannot
            // sign in or earn rewards.
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['role', 'created_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
