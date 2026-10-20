<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $pack = MeetingPack::query()->published()->orderBy('sort_order')->first();
        if ($pack === null) {
            $this->command?->warn('PaymentSeeder: published MeetingPack が存在しません。');
            return;
        }

        $fixed = User::query()->whereIn('email', [
            'student@certify-lms.test',
            'student-noquota@certify-lms.test',
        ])->where('role', 'student')->where('status', 'in_progress')->get()->keyBy('email');
        $demo = User::query()->where('role', 'student')->where('status', 'in_progress')
            ->whereNotIn('email', $fixed->keys()->all())->orderBy('id')->first();
        $users = collect([
            $fixed->get('student@certify-lms.test'),
            $fixed->get('student-noquota@certify-lms.test'),
            $demo,
        ])->filter();
        if ($users->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 対象studentが存在しません。');
            return;
        }

        foreach ($users as $index => $user) {
            $status = match ($index) {
                0 => PaymentStatus::Succeeded,
                1 => PaymentStatus::Pending,
                default => PaymentStatus::Failed,
            };
            $this->seedPayment($user, $pack, $status, $index + 1);
        }
    }

    private function seedPayment(User $user, MeetingPack $pack, PaymentStatus $status, int $number): void
    {
        $sessionId = 'seed_s_a_03_session_'.$number;
        $intentId = 'seed_s_a_03_intent_'.$number;

        DB::transaction(function () use ($user, $pack, $status, $sessionId, $intentId): void {
            $payment = Payment::query()->where('stripe_checkout_session_id', $sessionId)->first();
            if ($payment === null) {
                $payment = Payment::query()->create([
                    'user_id' => $user->id,
                    'meeting_pack_id' => $pack->id,
                    'meeting_pack_name_snapshot' => $pack->name,
                    'amount' => $pack->price,
                    'quantity' => $pack->meeting_count,
                    'currency' => 'jpy',
                    'status' => $status,
                    'stripe_checkout_session_id' => $sessionId,
                    'stripe_payment_intent_id' => $intentId,
                    'paid_at' => $status === PaymentStatus::Succeeded ? now() : null,
                    'failed_at' => $status === PaymentStatus::Failed ? now() : null,
                ]);
            }

            if ($payment->status !== PaymentStatus::Succeeded) {
                return;
            }

            MeetingQuotaTransaction::query()->firstOrCreate(
                ['related_payment_id' => $payment->id],
                [
                    'user_id' => $payment->user_id,
                    'type' => MeetingQuotaTransactionType::Purchased,
                    'amount' => $payment->quantity,
                    'occurred_at' => $payment->paid_at ?? now(),
                ],
            );
        });
    }
}
