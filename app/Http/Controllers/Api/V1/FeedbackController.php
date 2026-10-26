<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use App\Support\ApiResponse;
use App\Support\ClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app's "Send feedback" screen.
 */
class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback) {}

    /**
     * GET /api/v1/feedback/categories
     */
    public function categories(): JsonResponse
    {
        return ApiResponse::success('OK', [
            'items' => collect(Feedback::CATEGORIES)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /api/v1/feedback
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(Feedback::CATEGORIES))],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:190'],
        ]);

        $feedback = $this->feedback->submit($request->user(), $validated, ClientContext::fromRequest($request));

        return ApiResponse::success('Thanks! Your feedback was sent.', ['id' => $feedback->id], 201);
    }
}
