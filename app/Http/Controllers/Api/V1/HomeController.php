<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\DramaResource;
use App\Services\ContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The mobile home screen feed.
 *
 * One request returns every rail the home screen renders, because a short-drama
 * app is opened dozens of times a day and three extra round trips on cold start
 * is very visible to the user.
 */
class HomeController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    /**
     * GET /api/v1/home
     */
    public function index(): JsonResponse
    {
        $home = $this->content->home();

        return ApiResponse::success('OK', [
            'featured' => DramaResource::collection($home['featured']),
            'latest' => DramaResource::collection($home['latest']),
            'trending' => DramaResource::collection($home['trending']),
            'categories' => CategoryResource::collection($home['categories']),
        ]);
    }
}
