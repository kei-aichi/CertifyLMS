<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private mixed $stripeHttpClient;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->stripeHttpClient = ApiRequestor::httpClient();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            ApiRequestor::setHttpClient($this->stripeHttpClient);
        }
    }
}
