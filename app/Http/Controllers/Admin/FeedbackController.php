<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feedback inbox, plus the address each message is emailed to.
 */
class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback) {}

    /**
     * GET /admin/api/feedback?unread=1&category=
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->feedback
            ->adminSearch([
                'unread' => $request->boolean('unread'),
                'category' => $request->filled('category') ? $request->string('category')->toString() : null,
            ])
            ->through(fn (Feedback $f): array => [
                'id' => $f->id,
                'category' => $f->category,
                'category_label' => $f->categoryLabel(),
                'message' => $f->message,
                'contact' => $f->contact,
                'platform' => $f->platform,
                'app_version' => $f->app_version,
                'user' => $f->user === null ? null : [
                    'id' => $f->user->id,
                    'name' => $f->user->name,
                    'email' => $f->user->email,
                ],
                'is_read' => $f->read_at !== null,
                'emailed' => $f->emailed_at !== null,
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        return ApiResponse::paginated('Feedback loaded.', $paginator);
    }

    /**
     * POST /admin/api/feedback/{feedback}/read
     */
    public function markRead(Feedback $feedback): JsonResponse
    {
        $feedback->forceFill(['read_at' => $feedback->read_at ?? now()])->save();

        return ApiResponse::success('Marked as read.', ['is_read' => true]);
    }

    /**
     * GET /admin/api/feedback/settings
     */
    public function settings(): JsonResponse
    {
        return ApiResponse::success('OK', [
            'email' => $this->feedback->recipient(),
            'unread' => $this->feedback->unreadCount(),
        ]);
    }

    /**
     * PUT /admin/api/feedback/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['nullable', 'email', 'max:190']]);

        $this->feedback->setRecipient($validated['email'] ?? null);

        return ApiResponse::success('Feedback email saved.', ['email' => $this->feedback->recipient()]);
    }
}
