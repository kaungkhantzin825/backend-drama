<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Resources\Admin\CategoryAdminResource;
use App\Models\Category;
use App\Services\ContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Admin category management.
 *
 * JSON endpoints under `/admin/api/categories`, protected by the `admin` guard
 * and the `admin.role:admin,editor` middleware. The Inertia screens that drive
 * them arrive in Phase 7; keeping the API separate means content can be
 * managed (and tested) before the UI exists.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    /**
     * GET /admin/api/categories
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            'OK',
            CategoryAdminResource::collection($this->content->allCategories()),
        );
    }

    /**
     * POST /admin/api/categories
     */
    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->content->createCategory($request->categoryAttributes());

        return ApiResponse::success('Category created.', new CategoryAdminResource($category), 201);
    }

    /**
     * GET /admin/api/categories/{category}
     */
    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success('OK', new CategoryAdminResource($category));
    }

    /**
     * PATCH /admin/api/categories/{category}
     */
    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->content->updateCategory($category, $request->categoryAttributes());

        return ApiResponse::success('Category updated.', new CategoryAdminResource($category));
    }

    /**
     * DELETE /admin/api/categories/{category}
     *
     * The dramas inside the category are kept — the FK is `nullOnDelete` — so
     * removing a shelf never deletes catalogue content.
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->content->deleteCategory($category);

        return ApiResponse::success('Category deleted.');
    }
}
