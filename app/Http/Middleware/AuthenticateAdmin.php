<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the current request is authenticated against the *admin* guard.
 *
 * The admin panel has its own guard so that signing in to the admin panel
 * never affects the mobile API session, and vice versa.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }

            return redirect()->guest(route('admin.login'));
        }

        return $next($request);
    }
}
