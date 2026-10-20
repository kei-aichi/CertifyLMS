<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\StripeCheckoutClient;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;

final class CreateCheckoutSessionAction
{
    private const MINIMUM_AMOUNT = 50;

    public function __construct(private readonly StripeCheckoutClient $stripe) {}

    /** @return array{payment: Payment, session: Session} */
    public function __invoke(User $user, string $meetingPackId): array
    {
        $payment = DB::transaction(function () use ($user, $meetingPackId): Payment {
            $pack = MeetingPack::query()->lockForUpdate()->findOrFail($meetingPackId);

            if ($pack->status !== MeetingPackStatus::Published
                || $pack->price < self::MINIMUM_AMOUNT
                || $pack->meeting_count < 1) {
                abort(422, 'この面談パックは購入できません。');
            }

            return Payment::query()->create([
                'user_id' => $user->id,
                'meeting_pack_id' => $pack->id,
                'meeting_pack_name_snapshot' => $pack->name,
                'amount' => $pack->price,
                'quantity' => $pack->meeting_count,
                'currency' => 'jpy',
                'status' => PaymentStatus::Pending,
            ]);
        });

        $parameters = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $payment->currency,
                    'product_data' => ['name' => $payment->meeting_pack_name_snapshot],
                    'unit_amount' => $payment->amount,
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => $payment->id,
            'metadata' => ['payment_id' => $payment->id],
            'success_url' => route('meeting-quota.checkout.success', [
                'payment' => $payment->id,
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ]),
            'cancel_url' => route('meeting-quota.checkout.select', ['canceled' => 1]),
        ];

        $session = $this->stripe->createCheckoutSession($parameters, 's-a-03-payment:'.$payment->id);

        if (! is_string($session->id) || ! is_string($session->url) || $session->id === '' || $session->url === '') {
            throw new \RuntimeException('Stripe Checkout Sessionの応答が不正です。');
        }

        $payment->update(['stripe_checkout_session_id' => $session->id]);

        return ['payment' => $payment->fresh(), 'session' => $session];
    }
}
