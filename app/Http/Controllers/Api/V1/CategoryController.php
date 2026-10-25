<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Services\ContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catalogue categories ("shelves") for the browse screen.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    /**
     * GET /api/v1/categories
     *
     * Active categories in display order, each with the number of published
     * dramas it contains.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            'OK',
            CategoryResource::collection($this->content->activeCategoriesWithCounts()),
        );
    }
}
