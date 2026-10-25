<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the admin panel's separate session guard and role rules.
 *
 * @see \App\Http\Controllers\Admin\AdminAuthController
 * @see \App\Http\Middleware\EnsureUserHasRole
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── Login ────────────────────────────────────────────────────────────────

    public function test_admin_can_sign_in(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@test.com',
            'password' => 'secret-password',
        ]);

        $response = $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_login_rejects_a_wrong_password(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.com',
            'password' => 'secret-password',
        ]);

        $this->from('/admin/login')
            ->post('/admin/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_a_mobile_user_cannot_sign_in_to_the_admin_panel(): void
    {
        // A regular mobile account — role is null.
        User::factory()->create([
            'email' => 'mobile@test.com',
            'password' => 'secret-password',
        ]);

        $this->from('/admin/login')
            ->post('/admin/login', [
                'email' => 'mobile@test.com',
                'password' => 'secret-password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_a_banned_staff_member_cannot_sign_in(): void
    {
        User::factory()->admin()->banned()->create([
            'email' => 'banned@test.com',
            'password' => 'secret-password',
        ]);

        $this->from('/admin/login')
            ->post('/admin/login', [
                'email' => 'banned@test.com',
                'password' => 'secret-password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');
    }

    public function test_admin_login_validates_input(): void
    {
        $this->post('/admin/login', [])
            ->assertSessionHasErrors(['email', 'password']);
    }

    // ─── Access control ───────────────────────────────────────────────────────

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_reach_the_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Dashboard')
                ->where('auth.user.role', UserRole::Admin->value),
            );
    }

    public function test_editor_can_reach_the_dashboard(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.role', UserRole::Editor->value),
            );
    }

    public function test_a_mobile_user_session_does_not_grant_admin_access(): void
    {
        // Signing in on the `web` guard must not authenticate the admin guard.
        $mobile = User::factory()->create();

        $this->actingAs($mobile, 'web')
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    public function test_admin_can_sign_out(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->post('/admin/logout')
            ->assertRedirect('/admin/login');

        $this->assertGuest('admin');
    }
}
