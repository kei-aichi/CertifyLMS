<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

final class FailingGoogleHttpClient
{
    public static function make(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) {
                throw new \RuntimeException('Google network request blocked by test guard.');
            };
        });

        return new Client(['handler' => $stack]);
    }
}
