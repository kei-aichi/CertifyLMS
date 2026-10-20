<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MeetingQuota\CheckoutFulfillmentException;
use App\UseCases\MeetingQuota\FulfillCheckoutAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, FulfillCheckoutAction $fulfill): Response
    {
        try {
            $secret = config('services.stripe.webhook_secret');
            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('Webhook secret is not configured.');
            }
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('', 400);
        } catch (\Throwable $exception) {
            Log::error('Stripe Webhookの検証に失敗しました。', ['exception' => $exception::class]);
            return response('', 500);
        }

        $type = (string) ($event->type ?? '');
        if ($type === 'checkout.session.expired') {
            return response('', 200);
        }
        if ($type !== 'checkout.session.completed') {
            return response('', 200);
        }

        $session = $event->data->object ?? null;
        $sessionId = is_object($session) && is_string($session->id ?? null) ? $session->id : null;
        $metadata = is_object($session) ? ($session->metadata ?? null) : null;
        $paymentId = is_object($metadata) ? ($metadata->payment_id ?? null)
            : (is_array($metadata) ? ($metadata['payment_id'] ?? null) : null);
        if ($sessionId === null || ! is_string($paymentId) || $paymentId === '') {
            Log::warning('Stripe Webhookの決済識別子を確認できませんでした。');
            return response('', 200);
        }

        try {
            $fulfill($sessionId, $paymentId);
        } catch (CheckoutFulfillmentException $exception) {
            if ($exception->category === 'retryable') {
                return response('', 500);
            }
            Log::warning('Stripe Webhookの決済照合に失敗しました。', ['category' => $exception->category]);
        } catch (\Throwable $exception) {
            Log::error('Stripe Webhookの履行処理に失敗しました。', ['exception' => $exception::class]);
            return response('', 500);
        }

        return response('', 200);
    }
}
