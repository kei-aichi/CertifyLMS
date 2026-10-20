<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'meeting_pack_id' => MeetingPack::factory()->published(),
            'meeting_pack_name_snapshot' => '追加面談パック',
            'amount' => 15000,
            'quantity' => 5,
            'currency' => 'jpy',
            'status' => PaymentStatus::Pending->value,
            'stripe_checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
            'checkout_completed_at' => null,
            'paid_at' => null,
            'failed_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Succeeded->value]);
    }
}
