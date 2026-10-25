<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     *
     * @param  Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $admin = \Illuminate\Support\Facades\Auth::guard('admin')->user();

        return array_merge(parent::share($request), [
            // Expose the CSRF token so the SPA can send it with POSTs.
            'csrf_token' => csrf_token(),

            // The signed-in staff account, available to every admin screen.
            'auth' => [
                'user' => $admin === null ? null : [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role?->value,
                ],
            ],
        ]);
    }
}
