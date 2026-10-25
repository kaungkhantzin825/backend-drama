<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DramaIndexRequest;
use App\Http\Resources\DramaDetailResource;
use App\Http\Resources\DramaResource;
use App\Models\Drama;
use App\Services\ContentService;
use App\Services\LibraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Public video catalogue.
 *
 * Every endpoint here resolves only *published* videos: the visibility rule is
 * enforced in DramaRepository, so an unpublished slug returns 404 rather than
 * leaking a draft title.
 */
class DramaController extends Controller
{
    public function __construct(
        private readonly ContentService $content,
        private readonly LibraryService $library,
    ) {}

    /**
     * GET /api/v1/dramas
     *
     * Paginated catalogue with optional category / search / featured filters
     * and a whitelisted sort order. Each item carries its stream URL, so the
     * app's swipe feed can page through this list directly.
     */
    public function index(DramaIndexRequest $request): JsonResponse
    {
        $paginator = $this->content
            ->browseDramas($request->filters())
            // Map through the resource *before* paginating the envelope, so the
            // list payload matches the single-item payload shape exactly.
            ->through(fn (Drama $drama): array => (new DramaResource($drama))->resolve());

        return ApiResponse::paginated('Dramas loaded.', $paginator);
    }

    /**
     * GET /api/v1/dramas/{slug}
     *
     * Full detail. The app fetches it when a video comes on screen, which
     * records the view; signed-in viewers also get their "my list" state.
     */
    public function show(string $slug): JsonResponse
    {
        $drama = $this->content->dramaDetail($slug);

        if ($drama === null) {
            return ApiResponse::error('Drama not found.', null, 404);
        }

        // Public route: resolve the bearer token directly so anonymous viewers
        // still get the catalogue, while signed-in ones get their list state.
        $user = Auth::guard('sanctum')->user();

        if ($user !== null) {
            $drama->setAttribute('is_favorite', $this->library->isFavorite($user, $drama));
        }

        return ApiResponse::success('OK', new DramaDetailResource($drama));
    }
}
