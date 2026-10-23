<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

final class FailingStripeHttpClient implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        throw new ApiConnectionException('Stripe network request blocked by test guard.');
    }
}
