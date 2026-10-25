<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the admin panel accounts plus a small set of demo mobile users.
 *
 * Admin credentials come from the environment so nothing sensitive is
 * hard-coded in the repository.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmins();
        $this->seedDemoMobileUsers();

        // Remote config — default ads setup with Google's test ad units.
        $this->call(AppSettingSeeder::class);

        // Catalogue content — categories and standalone videos.
        $this->call(ContentSeeder::class);
        // Real, playable demo titles (YouTube-backed) for app development.
        $this->call(DemoVideoSeeder::class);
    }

    /**
     * Create the administrator and a content editor.
     */
    private function seedAdmins(): void
    {
        $adminEmail = config('app.admin_email') ?: env('ADMIN_EMAIL', 'admin@dramaloop.test');
        $adminPassword = env('ADMIN_PASSWORD', 'password');

        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Administrator',
                'role' => UserRole::Admin->value,
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'editor@dramaloop.test'],
            [
                'name' => 'Content Editor',
                'role' => UserRole::Editor->value,
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * A handful of mobile users so the admin panel and analytics have data
     * to show immediately after a fresh install.
     *
     * Keyed on `device_id` so the seeder can be re-run safely.
     */
    private function seedDemoMobileUsers(): void
    {
        $guest = User::query()->updateOrCreate(
            ['device_id' => 'demo-device-android-001'],
            [
                'name' => 'Guest Demo',
                'email' => null,
                'google_id' => null,
                'points_balance' => 250,
                'total_points_earned' => 250,
            ],
        );

        Device::query()->updateOrCreate(
            ['device_id' => 'demo-device-android-001'],
            [
                'user_id' => $guest->id,
                'platform' => 'android',
                'app_version' => '1.0.0',
                'last_active_at' => now(),
            ],
        );

        $googleUser = User::query()->updateOrCreate(
            ['email' => 'demo@dramaloop.test'],
            [
                'name' => 'Demo Google User',
                'google_id' => 'demo-google-sub-001',
                'points_balance' => 1200,
                'total_points_earned' => 1200,
            ],
        );

        Device::query()->updateOrCreate(
            ['device_id' => 'demo-device-android-002'],
            [
                'user_id' => $googleUser->id,
                'platform' => 'android',
                'app_version' => '1.0.0',
                'last_active_at' => now(),
            ],
        );
    }
}
