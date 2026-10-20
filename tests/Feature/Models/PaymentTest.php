<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_snapshots_and_relations_are_persisted(): void
    {
        $user = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->create(['name' => '購入時の名称']);
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name_snapshot' => '購入時の名称',
            'amount' => 12000,
            'quantity' => 4,
            'currency' => 'jpy',
            'status' => PaymentStatus::Succeeded,
        ]);

        $transaction = MeetingQuotaTransaction::factory()->purchased(4, $payment->id)->create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Purchased,
        ]);

        $this->assertTrue($payment->user->is($user));
        $this->assertTrue($payment->meetingPack->is($pack));
        $this->assertTrue($payment->quotaTransaction->is($transaction));
        $this->assertTrue($transaction->payment->is($payment));
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('購入時の名称', $payment->meeting_pack_name_snapshot);
        $this->assertSame(12000, $payment->amount);
        $this->assertSame(4, $payment->quantity);
        $this->assertSame('jpy', $payment->currency);
    }

    public function test_nullable_stripe_ids_can_repeat_null_but_non_null_values_are_unique(): void
    {
        Payment::factory()->count(2)->create();
        Payment::factory()->create(['stripe_checkout_session_id' => 'cs_test_unique']);

        $this->expectException(QueryException::class);
        Payment::factory()->create(['stripe_checkout_session_id' => 'cs_test_unique']);
    }

    public function test_pack_deletion_preserves_payment_snapshot_and_nulls_pack_reference(): void
    {
        $pack = MeetingPack::factory()->draft()->create();
        $payment = Payment::factory()->create([
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name_snapshot' => $pack->name,
            'quantity' => $pack->meeting_count,
            'amount' => $pack->price,
        ]);

        $pack->delete();

        $payment->refresh();
        $this->assertNull($payment->meeting_pack_id);
        $this->assertSame($pack->name, $payment->meeting_pack_name_snapshot);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'meeting_pack_id' => null]);
    }

    public function test_related_payment_id_is_unique_but_nullable(): void
    {
        $user = User::factory()->student()->create();
        MeetingQuotaTransaction::factory()->count(2)->create(['user_id' => $user->id]);
        $payment = Payment::factory()->create(['user_id' => $user->id]);
        MeetingQuotaTransaction::factory()->purchased(1, $payment->id)->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);
        MeetingQuotaTransaction::factory()->purchased(1, $payment->id)->create(['user_id' => $user->id]);
    }

    public function test_payment_with_related_quota_transaction_cannot_be_deleted(): void
    {
        $user = User::factory()->student()->create();
        $payment = Payment::factory()->create(['user_id' => $user->id]);
        MeetingQuotaTransaction::factory()->purchased(1, $payment->id)->create(['user_id' => $user->id]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not enforce this migration FK in the in-memory test connection; verify on MySQL.');
        }

        try {
            $payment->delete();
            $this->fail('Expected the payment deletion to be rejected by the foreign key constraint.');
        } catch (QueryException) {
            // The related quota transaction must prevent physical Payment deletion.
        }

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('meeting_quota_transactions', ['related_payment_id' => $payment->id]);
    }
}
