<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side remote configuration for the mobile app.
 *
 * Key-value rows with a JSON value, so a new setting is a new row rather than
 * a schema change. The mobile client reads them through GET /api/v1/app-config
 * (cached — see AppConfigService), which is what lets ads be switched off, the
 * interstitial gap tuned, or an ad unit swapped without shipping an app update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            // e.g. 'is_ads_enabled', 'ad_interval_seconds', 'ad_units'.
            $table->string('key', 64)->unique();

            // Any JSON value: bool, int, string or a nested structure.
            $table->json('value')->nullable();

            // Groups related keys for the admin panel ('ads', 'app', ...).
            $table->string('group', 32)->default('app')->index();

            $table->string('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
