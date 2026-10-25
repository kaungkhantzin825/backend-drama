<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel session authentication.
 *
 * Uses the dedicated `admin` guard so admin sessions are fully separate from
 * the mobile API's Sanctum tokens.
 */
class AdminAuthController extends Controller
{
    /**
     * Show the admin login screen.
     */
    public function showLogin(): Response
    {
        if (Auth::guard('admin')->check()) {
            return Inertia::redirect(route('admin.dashboard'));
        }

        return Inertia::render('admin/auth/Login', [
            'appName' => config('app.name'),
        ]);
    }

    /**
     * Attempt to sign a staff member in.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->whereNotNull('role')
            ->first();

        // Deliberately generic message so we never reveal whether the email
        // exists or which accounts are staff.
        $invalid = ValidationException::withMessages([
            'email' => 'Those credentials do not match our records.',
        ]);

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw $invalid;
        }

        if ($user->banned_at !== null) {
            throw ValidationException::withMessages([
                'email' => 'This account has been suspended.',
            ]);
        }

        Auth::guard('admin')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Sign the staff member out.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * The admin dashboard landing screen.
     */
    public function dashboard(Request $request): Response
    {
        $user = Auth::guard('admin')->user();

        return Inertia::render('admin/Dashboard', [
            'auth' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'role_label' => $user->role instanceof UserRole
                        ? UserRole::labels()[$user->role->value]
                        : null,
                ],
            ],
        ]);
    }
}
