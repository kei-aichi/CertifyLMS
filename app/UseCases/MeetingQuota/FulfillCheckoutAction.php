<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\CheckoutFulfillmentException;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\StripeCheckoutClient;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;

final class FulfillCheckoutAction
{
    public function __construct(private readonly StripeCheckoutClient $stripe) {}

    public function __invoke(string $sessionId, ?string $expectedPaymentId = null): Payment
    {
        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
        } catch (\Throwable $e) {
            throw CheckoutFulfillmentException::retryable(previous: $e);
        }
        $paymentId = $this->stringMetadata($session, 'payment_id');

        if ($paymentId === null || ($expectedPaymentId !== null && $expectedPaymentId !== $paymentId)) {
            throw CheckoutFulfillmentException::invalid('決済と購入情報の対応を確認できませんでした。');
        }

        $candidate = Payment::query()->find($paymentId);
        if ($candidate === null) {
            throw CheckoutFulfillmentException::invalid('購入情報を確認できませんでした。');
        }

        $this->assertSessionMatchesPayment($session, $sessionId, $candidate, $paymentId);

        try {
            return DB::transaction(function () use ($session, $sessionId, $candidate, $paymentId): Payment {
            // Reservation consumption uses the same User-first order.
            User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            $this->assertSessionMatchesPayment($session, $sessionId, $payment, $paymentId);
            $this->assertIdentifiersNotBoundToAnotherPayment($payment, $sessionId, $this->paymentIntentId($session));

            $transaction = MeetingQuotaTransaction::query()
                ->where('related_payment_id', $payment->id)
                ->first();

            if ($payment->status === PaymentStatus::Succeeded) {
                if ($transaction !== null && $this->isValidPurchaseTransaction($transaction, $payment)) {
                    return $payment;
                }

                throw CheckoutFulfillmentException::invalid('決済履歴の整合性を確認できませんでした。');
            }

            if ($payment->status !== PaymentStatus::Pending || $transaction !== null) {
                throw CheckoutFulfillmentException::invalid('この決済は履行できない状態です。');
            }

            $now = now();
            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'stripe_checkout_session_id' => $payment->stripe_checkout_session_id ?? $sessionId,
                'stripe_payment_intent_id' => $this->paymentIntentId($session),
                'paid_at' => $now,
            ]);

            MeetingQuotaTransaction::query()->create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => $now,
            ]);

            return $payment->fresh();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                throw CheckoutFulfillmentException::invalid('決済履歴の整合性を確認できませんでした。');
            }
            throw CheckoutFulfillmentException::retryable(previous: $e);
        } catch (CheckoutFulfillmentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw CheckoutFulfillmentException::retryable(previous: $e);
        }
    }

    private function assertIdentifiersNotBoundToAnotherPayment(Payment $payment, string $sessionId, ?string $intentId): void
    {
        $sessionOwner = Payment::query()->where('stripe_checkout_session_id', $sessionId)
            ->where('id', '<>', $payment->id)->exists();
        $intentOwner = $intentId !== null && Payment::query()->where('stripe_payment_intent_id', $intentId)
            ->where('id', '<>', $payment->id)->exists();

        if ($sessionOwner || $intentOwner) {
            throw CheckoutFulfillmentException::invalid('決済識別子が別の購入情報に紐付いています。');
        }
    }

    private function assertSessionMatchesPayment(Session $session, string $sessionId, Payment $payment, string $paymentId): void
    {
        if ($session->id !== $sessionId
            || ($session->mode ?? null) !== 'payment'
            || ($session->payment_status ?? null) !== 'paid'
            || ($session->currency ?? null) !== $payment->currency
            || ($session->amount_total ?? null) !== $payment->amount
            || ($session->client_reference_id ?? null) !== $paymentId
            || $this->stringMetadata($session, 'payment_id') !== $paymentId) {
            if (($session->payment_status ?? null) !== 'paid') {
                throw CheckoutFulfillmentException::unpaid();
            }
            throw CheckoutFulfillmentException::invalid('Stripe決済情報が購入情報と一致しません。');
        }

        $intentId = $this->paymentIntentId($session);
        if ($intentId === null || ($payment->stripe_payment_intent_id !== null && $payment->stripe_payment_intent_id !== $intentId)) {
            throw CheckoutFulfillmentException::invalid('PaymentIntentを確認できませんでした。');
        }

        if ($payment->stripe_checkout_session_id !== null && $payment->stripe_checkout_session_id !== $sessionId) {
            throw CheckoutFulfillmentException::invalid('Checkout Sessionが購入情報と一致しません。');
        }
    }

    private function isValidPurchaseTransaction(MeetingQuotaTransaction $transaction, Payment $payment): bool
    {
        return $transaction->user_id === $payment->user_id
            && $transaction->type === MeetingQuotaTransactionType::Purchased
            && $transaction->amount === $payment->quantity
            && $transaction->related_payment_id === $payment->id;
    }

    private function paymentIntentId(Session $session): ?string
    {
        return is_string($session->payment_intent ?? null) && $session->payment_intent !== ''
            ? $session->payment_intent
            : null;
    }

    private function stringMetadata(Session $session, string $key): ?string
    {
        $value = $session->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
