<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders exceptions from the mobile API using the unified envelope:
 *   { "success": false, "message": string, "data": any }
 *
 * Keeping this in one place guarantees the Flutter client never has to
 * special-case an error shape.
 */
class ExceptionRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        // ─── Validation ─────────────────────────────────────────────────────
        // The client reads field-level messages from `data.errors`.
        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['errors' => $e->errors()],
                $e->status,
            );
        }

        // ─── Unauthenticated ─────────────────────────────────────────────────
        if ($e instanceof AuthenticationException) {
            return ApiResponse::error('Unauthenticated.', null, 401);
        }

        // ─── Authorisation ───────────────────────────────────────────────────
        if ($e instanceof AuthorizationException) {
            return ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                null,
                403,
            );
        }

        // ─── Model not found ─────────────────────────────────────────────────
        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::error('The requested resource was not found.', null, 404);
        }

        if ($e instanceof NotFoundHttpException) {
            return ApiResponse::error('The requested resource was not found.', null, 404);
        }

        // ─── Rate limiting ───────────────────────────────────────────────────
        if ($e instanceof ThrottleRequestsException) {
            $seconds = $e->getHeaders()['Retry-After'] ?? 60;

            return ApiResponse::error(
                'Too many requests. Please slow down and try again shortly.',
                ['retry_after' => (int) $seconds],
                429,
            );
        }

        // ─── Explicit JSON responses pass straight through ───────────────────
        if ($e instanceof HttpResponseException) {
            $response = $e->getResponse();

            // Laravel's `prepareException()` converts AuthenticationException
            // into a HttpResponseException carrying the default
            // {"message": "Unauthenticated."} body before render callbacks
            // run. Re-shape it into the unified envelope.
            if ($this->isUnauthenticatedResponse($response)) {
                return ApiResponse::error('Unauthenticated.', null, 401);
            }

            if ($response instanceof JsonResponse) {
                return $response;
            }

            return ApiResponse::error('Request failed.', null, $response->getStatusCode());
        }

        // ─── Other HTTP exceptions (405, 409, 422, …) ────────────────────────
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : $this->defaultMessageFor($status),
                null,
                $status,
            );
        }

        // ─── Anything else: hide internals in production ─────────────────────
        $message = config('app.debug')
            ? $e->getMessage()
            : 'Something went wrong on our side. Please try again.';

        return ApiResponse::error($message, null, 500);
    }

    /**
     * Detect Laravel's default "unauthenticated" JSON response.
     *
     * The framework renders `{"message": "Unauthenticated."}` with a 401
     * status when the `auth` middleware fails.
     */
    private function isUnauthenticatedResponse(mixed $response): bool
    {
        if (! $response instanceof JsonResponse) {
            return false;
        }

        if ($response->getStatusCode() !== 401) {
            return false;
        }

        $data = $response->getData(true);

        // Laravel's default body has exactly one `message` key.
        return is_array($data)
            && isset($data['message'])
            && str_contains(strtolower((string) $data['message']), 'unauthenticated')
            && ! array_key_exists('success', $data);
    }

    /**
     * A human-readable message for common HTTP status codes.
     */
    private function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Unauthenticated.',
            403 => 'You do not have permission to perform this action.',
            404 => 'The requested resource was not found.',
            405 => 'That method is not allowed for this endpoint.',
            409 => 'The request conflicts with the current state.',
            419 => 'The session expired. Please sign in again.',
            422 => 'The given data was invalid.',
            429 => 'Too many requests. Please slow down and try again shortly.',
            default => 'The request could not be completed.',
        };
    }
}
