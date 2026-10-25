<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The unified response envelope is a contract with the Flutter client, so it
 * is asserted explicitly rather than only as a side effect of other tests.
 */
class ApiResponseFormatTest extends TestCase
{
    public function test_success_response_has_the_expected_shape(): void
    {
        $response = ApiResponse::success('All good.', ['id' => 7]);

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertSame(
            ['success', 'message', 'data'],
            array_keys($body),
            'The envelope keys must be exactly success, message and data.',
        );
        $this->assertTrue($body['success']);
        $this->assertSame('All good.', $body['message']);
        $this->assertSame(['id' => 7], $body['data']);
    }

    public function test_error_response_has_the_expected_shape(): void
    {
        $response = ApiResponse::error('Nope.', ['reason' => 'x'], 422);

        $this->assertSame(422, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertFalse($body['success']);
        $this->assertSame('Nope.', $body['message']);
        $this->assertSame(['reason' => 'x'], $body['data']);
    }

    public function test_paginated_response_includes_meta(): void
    {
        $response = ApiResponse::paginated('List loaded.', $this->makePaginator());

        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame(1, $body['meta']['current_page']);
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(15, $body['meta']['per_page']);
        // Three items at 15 per page is a single page.
        $this->assertSame(1, $body['meta']['last_page']);
        $this->assertSame(1, $body['meta']['from']);
        $this->assertSame(3, $body['meta']['to']);
    }

    public function test_data_defaults_to_null(): void
    {
        $this->assertNull(ApiResponse::success()->getData(true)['data']);
    }

    public function test_message_defaults_to_ok(): void
    {
        $this->assertSame('OK', ApiResponse::success()->getData(true)['message']);
    }

    /**
     * A paginator over three in-memory items — no database needed.
     */
    private function makePaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            collect([1, 2, 3]),
            3,
            15,
            1,
            ['path' => 'http://localhost'],
        );
    }
}
