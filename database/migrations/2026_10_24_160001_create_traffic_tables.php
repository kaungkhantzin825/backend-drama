<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traffic monitoring.
 *
 * Every table except `traffic_flags` is an **aggregate**: one row per bucket,
 * incremented with `INSERT ... ON DUPLICATE KEY UPDATE n = n + 1`. Nothing logs
 * one row per request, so the tables stay small no matter how busy the app is.
 *
 * Bucket-key columns are NOT NULL with '' defaults on purpose: MySQL treats
 * NULLs as distinct in a unique index, which would break the upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── API traffic, per hour ───────────────────────────────────────────
        Schema::create('api_traffic_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('hour');
            $table->string('route', 96)->default('');
            $table->string('method', 8)->default('');
            // 2, 3, 4 or 5 — the status code's hundreds digit.
            $table->unsignedTinyInteger('status_class');
            $table->string('platform', 16)->default('');
            $table->string('app_version', 24)->default('');

            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('slow_requests')->default(0);

            $table->unique(
                ['hour', 'route', 'method', 'status_class', 'platform', 'app_version'],
                'api_traffic_bucket_unique',
            );
            $table->index('hour');
        });

        // ─── User activity, per user per day ─────────────────────────────────
        Schema::create('user_daily_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('platform', 16)->default('');
            $table->string('app_version', 24)->default('');
            // A/B ad experiment variant the user was in that day ('' = none).
            $table->string('ab_group', 32)->default('');

            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('watch_seconds')->default(0);
            $table->unsignedInteger('ad_impressions')->default(0);

            $table->unique(['user_id', 'date']);
            $table->index(['date', 'platform']);
            $table->index(['date', 'ab_group']);
        });

        // ─── Ad traffic, per waterfall step per day ──────────────────────────
        Schema::create('ad_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('platform', 16)->default('');
            $table->string('format', 16);
            $table->string('placement', 32)->default('');
            $table->string('unit_id', 64)->default('');
            // 1-based position in the waterfall.
            $table->unsignedTinyInteger('tier')->default(1);
            $table->string('ab_group', 32)->default('');

            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('fills')->default(0);
            $table->unsignedInteger('no_fills')->default(0);
            $table->unsignedInteger('timeouts')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('show_failures')->default(0);
            // Impression-level ad revenue reported by the SDK (onPaidEvent),
            // in micros of the account currency.
            $table->unsignedBigInteger('revenue_micros')->default(0);

            $table->unique(
                ['date', 'platform', 'format', 'placement', 'unit_id', 'tier', 'ab_group'],
                'ad_traffic_bucket_unique',
            );
            $table->index('date');
        });

        // ─── Suspicious traffic ──────────────────────────────────────────────
        Schema::create('traffic_flags', function (Blueprint $table) {
            $table->id();

            // 'ip' | 'device' | 'user'
            $table->string('subject_type', 16);
            $table->string('subject', 128);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // See TrafficFlagReason.
            $table->string('reason', 40);
            // 'low' | 'medium' | 'high'
            $table->string('severity', 16)->default('medium');
            $table->json('details')->nullable();

            // How many times the detector has seen this again.
            $table->unsignedInteger('hits')->default(1);

            // 'open' | 'dismissed' | 'blocked'
            $table->string('status', 16)->default('open');

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // One flag per subject per reason; repeats bump `hits`.
            $table->unique(['subject_type', 'subject', 'reason']);
            $table->index(['status', 'severity']);
            $table->index(['subject_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_flags');
        Schema::dropIfExists('ad_traffic_daily');
        Schema::dropIfExists('user_daily_activity');
        Schema::dropIfExists('api_traffic_hourly');
    }
};
