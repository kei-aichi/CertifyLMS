<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutClient
{
    /** @param array<string, mixed> $parameters */
    public function createCheckoutSession(array $parameters, string $idempotencyKey): Session
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('Stripeの秘密鍵が設定されていません。');
        }

        $client = new StripeClient($secret);

        /** @var Session $session */
        $session = $client->checkout->sessions->create(
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );

        return $session;
    }
}
