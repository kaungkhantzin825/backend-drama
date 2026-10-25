<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to staff accounts holding one of the given roles.
 *
 * Used on the Inertia admin panel. A mobile user (role === null) who somehow
 * reaches an admin route gets bounced, and an `editor` cannot touch the
 * revenue-critical screens that require `admin`.
 */
class EnsureUserHasRole
{
    /**
     * @param  string  ...$roles  Roles permitted to access the route.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::guard('admin')->user();

        if ($user === null) {
            // Not signed in to the admin guard at all.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }

            return redirect()->guest(route('admin.login'));
        }

        $allowed = array_map(
            static fn (string $role): string => $role === 'admin' ? UserRole::Admin->value : $role,
            $roles,
        );

        if ($user->role === null || ! in_array($user->role->value, $allowed, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to access this area.',
                    'data' => null,
                ], 403);
            }

            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
