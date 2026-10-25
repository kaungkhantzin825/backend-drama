<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of reported ad impressions — the fraud-monitoring surface.
 */
class AdEventController extends Controller
{
    /**
     * GET /admin/api/ad-events?status=&placement=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = AdEvent::query()
            ->with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('placement'), fn ($q) => $q->where('placement', $request->string('placement')->toString()))
            ->latest('id')
            ->paginate(30)
            ->through(fn (AdEvent $event): array => [
                'id' => $event->id,
                'user' => $event->user === null ? null : [
                    'id' => $event->user->id,
                    'name' => $event->user->name,
                    'email' => $event->user->email,
                ],
                'device_id' => $event->device_id,
                'placement' => $event->placement,
                'provider' => $event->provider,
                'reward_points' => $event->reward_points,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Ad events loaded.', $paginator);
    }
}
