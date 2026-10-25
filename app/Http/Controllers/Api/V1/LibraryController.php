<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RewardLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProgressRequest;
use App\Http\Resources\DramaResource;
use App\Models\Drama;
use App\Models\WatchProgress;
use App\Services\LibraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-viewer library: progress, continue watching, history, my list.
 */
class LibraryController extends Controller
{
    public function __construct(private readonly LibraryService $library) {}

    /**
     * POST /api/v1/dramas/{drama}/progress
     *
     * A playback heartbeat. Also the only place watch-time points are earned.
     */
    public function progress(ProgressRequest $request, Drama $drama): JsonResponse
    {
        if (! $drama->isVisible()) {
            return ApiResponse::error('Drama not found.', null, 404);
        }

        try {
            $result = $this->library->record(
                $request->user(),
                $drama,
                $request->integer('position_seconds'),
                $request->integer('duration_seconds'),
                $request->filled('watched_seconds') ? $request->integer('watched_seconds') : null,
            );
        } catch (RewardLimitException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success('Progress saved.', [
            'progress' => $this->presentProgress($result['progress']),
            'points_awarded' => $result['points_awarded'],
            'wallet' => $result['wallet'],
        ]);
    }

    /**
     * GET /api/v1/continue-watching
     */
    public function continueWatching(Request $request): JsonResponse
    {
        $rows = $this->library->continueWatching($request->user());

        return ApiResponse::success('Continue watching loaded.', [
            'items' => $rows->map(fn (WatchProgress $row): array => $this->presentProgress($row, true))->values()->all(),
        ]);
    }

    /**
     * GET /api/v1/history
     */
    public function history(Request $request): JsonResponse
    {
        $paginator = $this->library
            ->history($request->user())
            ->through(fn (WatchProgress $row): array => $this->presentProgress($row, true));

        return ApiResponse::paginated('History loaded.', $paginator);
    }

    /**
     * GET /api/v1/favorites
     */
    public function favorites(Request $request): JsonResponse
    {
        $paginator = $this->library
            ->favorites($request->user())
            ->through(fn (Drama $drama): array => (new DramaResource($drama))->resolve());

        return ApiResponse::paginated('Favorites loaded.', $paginator);
    }

    /**
     * POST /api/v1/dramas/{drama}/favorite
     *
     * Toggles, so the client does not have to track the current state to send
     * the right verb.
     */
    public function toggleFavorite(Request $request, Drama $drama): JsonResponse
    {
        if (! $drama->isVisible()) {
            return ApiResponse::error('Drama not found.', null, 404);
        }

        $isFavorite = $this->library->toggleFavorite($request->user(), $drama);

        return ApiResponse::success(
            $isFavorite ? 'Added to your list.' : 'Removed from your list.',
            ['is_favorite' => $isFavorite],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProgress(WatchProgress $progress, bool $withDrama = false): array
    {
        $payload = [
            'drama_id' => $progress->drama_id,
            'position_seconds' => (int) $progress->position_seconds,
            'duration_seconds' => (int) $progress->duration_seconds,
            'percent' => $progress->percentWatched(),
            'is_completed' => (bool) $progress->is_completed,
            'last_watched_at' => $progress->last_watched_at?->toIso8601String(),
        ];

        if ($withDrama) {
            $payload['drama'] = $progress->drama !== null
                ? (new DramaResource($progress->drama))->resolve()
                : null;
        }

        return $payload;
    }
}
