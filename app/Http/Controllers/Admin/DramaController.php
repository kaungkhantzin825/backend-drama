<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DramaRequest;
use App\Http\Resources\Admin\DramaAdminResource;
use App\Models\Drama;
use App\Services\ContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin drama management.
 *
 * JSON endpoints under `/admin/api/dramas`, protected by the `admin` guard and
 * the `admin.role:admin,editor` middleware.
 */
class DramaController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    /**
     * GET /admin/api/dramas?status=&category_id=&q=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'per_page' => $request->filled('per_page') ? $request->integer('per_page') : null,
        ];

        $paginator = $this->content
            ->adminDramas($filters)
            ->through(fn (Drama $drama): array => (new DramaAdminResource($drama))->resolve());

        return ApiResponse::paginated('Dramas loaded.', $paginator);
    }

    /**
     * POST /admin/api/dramas
     */
    public function store(DramaRequest $request): JsonResponse
    {
        $drama = $this->content->createDrama($request->dramaAttributes());

        return ApiResponse::success('Drama created.', new DramaAdminResource($drama), 201);
    }

    /**
     * GET /admin/api/dramas/{drama}
     */
    public function show(Drama $drama): JsonResponse
    {
        $drama->load('category');

        return ApiResponse::success('OK', new DramaAdminResource($drama));
    }

    /**
     * PATCH /admin/api/dramas/{drama}
     */
    public function update(DramaRequest $request, Drama $drama): JsonResponse
    {
        $drama = $this->content->updateDrama($drama, $request->dramaAttributes());

        return ApiResponse::success('Drama updated.', new DramaAdminResource($drama));
    }

    /**
     * POST /admin/api/dramas/{drama}/publish
     *
     * A dedicated action so publishing is one click and always stamps
     * `published_at` correctly.
     */
    public function publish(Drama $drama): JsonResponse
    {
        $drama = $this->content->publishDrama($drama);

        return ApiResponse::success('Drama published.', new DramaAdminResource($drama));
    }

    /**
     * DELETE /admin/api/dramas/{drama}
     */
    public function destroy(Drama $drama): JsonResponse
    {
        $this->content->deleteDrama($drama);

        return ApiResponse::success('Drama deleted.');
    }
}
