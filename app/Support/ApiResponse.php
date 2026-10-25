<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified API response envelope.
 *
 * Every JSON endpoint in this application MUST use this shape so the mobile
 * client can rely on a single parsing strategy:
 *
 *   { "success": bool, "message": string, "data": any }
 *
 * @see \App\Exceptions\Handler — renders validation errors and unauthenticated
 *      responses using the very same envelope.
 */
final class ApiResponse
{
    /**
     * Build a successful response.
     *
     * @param  string  $message  Human readable, non-technical description.
     * @param  mixed   $data     Payload. API Resources are unwrapped to their JSON.
     */
    public static function success(
        string $message = 'OK',
        mixed $data = null,
        int $status = 200,
    ): JsonResponse {
        return self::envelope(true, $message, $data, $status);
    }

    /**
     * Build a failure response.
     *
     * @param  string  $message  Human readable explanation of the failure.
     * @param  mixed   $data     Optional structured detail (e.g. validation errors).
     */
    public static function error(
        string $message = 'An error occurred.',
        mixed $data = null,
        int $status = 400,
    ): JsonResponse {
        return self::envelope(false, $message, $data, $status);
    }

    /**
     * Build a paginated response.
     *
     * `data` holds the paginator payload plus a `meta` block describing the
     * current page, so the Flutter client can render infinite-scroll lists.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator|JsonResource  $paginator
     */
    public static function paginated(
        string $message = 'OK',
        mixed $paginator = null,
        int $status = 200,
    ): JsonResponse {
        $resolved = $paginator instanceof JsonResource ? $paginator->response()->getData(true) : $paginator;

        $meta = [];

        // Laravel paginators expose their state via these methods.
        if (is_object($resolved) && method_exists($resolved, 'currentPage')) {
            $meta = [
                'current_page' => $resolved->currentPage(),
                'last_page' => $resolved->lastPage(),
                'per_page' => $resolved->perPage(),
                'total' => $resolved->total(),
                'from' => $resolved->firstItem(),
                'to' => $resolved->lastItem(),
            ];
        }

        return self::envelope(true, $message, $resolved, $status, extra: ['meta' => $meta]);
    }

    /**
     * Assemble the envelope. Kept private so the shape can never drift.
     */
    private static function envelope(
        bool $success,
        string $message,
        mixed $data,
        int $status,
        array $extra = [],
    ): JsonResponse {
        return response()->json(
            array_merge([
                'success' => $success,
                'message' => $message,
                'data' => $data,
            ], $extra),
            $status,
        );
    }
}
