<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TrafficFlagReason;
use App\Enums\TrafficFlagStatus;
use App\Http\Controllers\Controller;
use App\Models\TrafficFlag;
use App\Services\TrafficGuard;
use App\Services\TrafficReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin traffic monitoring: activity, API, ads, A/B, suspicious traffic.
 */
class TrafficController extends Controller
{
    public function __construct(
        private readonly TrafficReportService $reports,
        private readonly TrafficGuard $guard,
    ) {}

    /** GET /admin/api/traffic/activity?days= */
    public function activity(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', $this->reports->activity($this->days($request))
            + ['headline' => $this->reports->headline()]);
    }

    /** GET /admin/api/traffic/api?days= */
    public function api(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', $this->reports->api($this->days($request)));
    }

    /** GET /admin/api/traffic/ads?days= */
    public function ads(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', $this->reports->ads($this->days($request)));
    }

    /** GET /admin/api/traffic/ab?days= */
    public function ab(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', $this->reports->ab($this->days($request)));
    }

    /** GET /admin/api/traffic/export?report=&days= — CSV download. */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'report' => ['required', Rule::in(TrafficReportService::REPORTS)],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $rows = $this->reports->csvRows($validated['report'], $days);
        $filename = sprintf('dramaloop-%s-%dd-%s.csv', $validated['report'], $days, now()->format('Ymd'));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─── Suspicious traffic ──────────────────────────────────────────────────

    /** GET /admin/api/traffic/flags?status=&reason=&page= */
    public function flags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(TrafficFlagStatus::values())],
            'reason' => ['nullable', Rule::in(TrafficFlagReason::values())],
        ]);

        $paginator = $this->guard->search($validated)->through(fn (TrafficFlag $f): array => [
            'id' => $f->id,
            'subject_type' => $f->subject_type,
            'subject' => $f->subject,
            'user' => $f->user === null ? null : ['id' => $f->user->id, 'name' => $f->user->name, 'email' => $f->user->email],
            'reason' => $f->reason->value,
            'reason_label' => $f->reason->label(),
            'severity' => $f->severity,
            'details' => $f->details,
            'hits' => $f->hits,
            'status' => $f->status->value,
            'status_label' => $f->status->label(),
            'first_seen_at' => $f->first_seen_at?->toIso8601String(),
            'last_seen_at' => $f->last_seen_at?->toIso8601String(),
            'resolved_by' => $f->resolver?->name,
        ]);

        return ApiResponse::paginated('Flags loaded.', $paginator);
    }

    /** POST /admin/api/traffic/flags/{flag}/block */
    public function block(Request $request, TrafficFlag $flag): JsonResponse
    {
        $this->guard->block($flag, $request->user('admin'));

        return ApiResponse::success('Blocked.', ['id' => $flag->id, 'status' => $flag->status->value]);
    }

    /** POST /admin/api/traffic/flags/{flag}/dismiss */
    public function dismiss(Request $request, TrafficFlag $flag): JsonResponse
    {
        $this->guard->dismiss($flag, $request->user('admin'));

        return ApiResponse::success('Dismissed.', ['id' => $flag->id, 'status' => $flag->status->value]);
    }

    /** POST /admin/api/traffic/flags/{flag}/unblock */
    public function unblock(Request $request, TrafficFlag $flag): JsonResponse
    {
        $this->guard->unblock($flag, $request->user('admin'));

        return ApiResponse::success('Unblocked.', ['id' => $flag->id, 'status' => $flag->status->value]);
    }

    /** POST /admin/api/traffic/scan — run the detectors now. */
    public function scan(): JsonResponse
    {
        $found = $this->guard->scan();

        return ApiResponse::success(sprintf('Scan finished: %d subject(s) flagged.', array_sum($found)), $found);
    }

    private function days(Request $request): int
    {
        return max(1, min(90, (int) $request->query('days', 7)));
    }
}
