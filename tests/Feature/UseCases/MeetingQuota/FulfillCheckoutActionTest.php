<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\CheckoutFulfillmentException;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\Services\Stripe\StripeCheckoutClient;
use App\UseCases\MeetingQuota\FulfillCheckoutAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Checkout\Session;
use Tests\TestCase;

#[Group('external-api')]
class FulfillCheckoutActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_paid_session_fulfills_snapshot_and_increases_remaining_quota(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 2]);
        $pack = MeetingPack::factory()->published()->create(['name' => '旧名称', 'price' => 500, 'meeting_count' => 3]);
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name_snapshot' => '購入時名称',
            'amount' => 500,
            'quantity' => 3,
            'currency' => 'jpy',
        ]);
        $session = $this->makeSession($payment, 'cs_paid', 'pi_paid');
        $this->mockStripe($session);

        $before = app(MeetingQuotaService::class)->remaining($user);
        $result = app(FulfillCheckoutAction::class)('cs_paid');

        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'stripe_checkout_session_id' => 'cs_paid',
            'stripe_payment_intent_id' => 'pi_paid',
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
        ]);
        $this->assertSame($before + 3, app(MeetingQuotaService::class)->remaining($user->fresh()));
    }

    public function test_same_payment_is_idempotent(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $session = $this->makeSession($payment, 'cs_idempotent', 'pi_idempotent');
        $this->mockStripe($session, 2);
        $action = app(FulfillCheckoutAction::class);

        $action('cs_idempotent');
        $action('cs_idempotent');

        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_session_id_can_be_recovered_from_metadata_when_payment_has_no_session_id(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $this->mockStripe($this->makeSession($payment, 'cs_recovered', 'pi_recovered'));

        app(FulfillCheckoutAction::class)('cs_recovered');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'stripe_checkout_session_id' => 'cs_recovered']);
    }

    public function test_unpaid_or_mismatched_session_does_not_fulfill(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        foreach ([
            'unpaid' => ['payment_status' => 'unpaid'],
            'mode' => ['mode' => 'setup'],
            'currency' => ['currency' => 'usd'],
            'amount' => ['amount_total' => 501],
            'client_reference' => ['client_reference_id' => 'other'],
            'metadata' => ['metadata' => ['payment_id' => 'other']],
            'intent' => ['payment_intent' => 'pi_other'],
        ] as $label => $override) {
            $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
            if ($label === 'intent') {
                $payment->update(['stripe_payment_intent_id' => 'pi_existing']);
            }
            $session = $this->makeSession($payment, 'cs_mismatch_'.Payment::query()->count(), 'pi_expected', $override);
            $this->mockStripe($session);
            try {
                app(FulfillCheckoutAction::class)($session->id);
                $this->fail('Expected fulfillment to be rejected: '.$label);
            } catch (CheckoutFulfillmentException) {
                // Expected safe rejection.
            }
        }
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_succeeded_without_valid_transaction_is_not_treated_as_idempotent_success(): void
    {
        $payment = Payment::factory()->succeeded()->create(['amount' => 500, 'quantity' => 3]);
        $this->mockStripe($this->makeSession($payment, 'cs_inconsistent', 'pi_inconsistent'));

        $this->expectException(CheckoutFulfillmentException::class);
        app(FulfillCheckoutAction::class)('cs_inconsistent');
    }

    public function test_payment_and_transaction_inconsistency_is_rejected(): void
    {
        $payment = Payment::factory()->succeeded()->create(['amount' => 500, 'quantity' => 3]);
        MeetingQuotaTransaction::factory()->purchased(99, $payment->id)->create(['user_id' => $payment->user_id]);
        $this->mockStripe($this->makeSession($payment, 'cs_bad_transaction', 'pi_bad_transaction'));

        $this->expectException(CheckoutFulfillmentException::class);
        app(FulfillCheckoutAction::class)('cs_bad_transaction');
    }

    public function test_failed_and_refunded_payments_are_not_fulfilled(): void
    {
        foreach ([PaymentStatus::Failed, PaymentStatus::Refunded] as $status) {
            $payment = Payment::factory()->create(['status' => $status, 'amount' => 500, 'quantity' => 3]);
            $this->mockStripe($this->makeSession($payment, 'cs_'.$status->value, 'pi_'.$status->value));

            $this->expectException(CheckoutFulfillmentException::class);
            app(FulfillCheckoutAction::class)('cs_'.$status->value);
        }
    }

    public function test_pending_payment_with_purchase_transaction_is_rejected(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        MeetingQuotaTransaction::factory()->purchased(3, $payment->id)->create(['user_id' => $payment->user_id]);
        $this->mockStripe($this->makeSession($payment, 'cs_pending_tx', 'pi_pending_tx'));

        $this->expectException(CheckoutFulfillmentException::class);
        app(FulfillCheckoutAction::class)('cs_pending_tx');
    }

    public function test_stripe_timeout_is_classified_as_retryable(): void
    {
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('retrieveCheckoutSession')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(StripeCheckoutClient::class, $mock);

        try {
            app(FulfillCheckoutAction::class)('cs_timeout');
            $this->fail('Expected retryable exception.');
        } catch (CheckoutFulfillmentException $e) {
            $this->assertSame('retryable', $e->category);
        }
    }

    public function test_same_session_or_payment_intent_cannot_be_bound_to_another_payment(): void
    {
        $owner = Payment::factory()->create(['amount' => 500, 'quantity' => 3, 'stripe_checkout_session_id' => 'cs_owned', 'stripe_payment_intent_id' => 'pi_owned']);
        $other = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $this->mockStripe($this->makeSession($other, 'cs_owned', 'pi_new'));
        $this->expectException(CheckoutFulfillmentException::class);
        app(FulfillCheckoutAction::class)('cs_owned');
        $this->assertNotNull($owner->id);
    }

    public function test_snapshot_and_paid_status_are_sufficient_after_pack_deletion_and_user_status_change(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $pack = MeetingPack::factory()->published()->create(['price' => 500, 'meeting_count' => 3]);
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name_snapshot' => '購入時名称',
            'amount' => 500,
            'quantity' => 3,
        ]);
        $pack->delete();
        $user->update(['status' => \App\Enums\UserStatus::Graduated]);
        $this->mockStripe($this->makeSession($payment, 'cs_snapshot', 'pi_snapshot'));

        app(FulfillCheckoutAction::class)('cs_snapshot');

        $this->assertDatabaseHas('meeting_quota_transactions', ['related_payment_id' => $payment->id, 'amount' => 3]);
    }

    private function mockStripe(Session $session, int $times = 1): void
    {
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('retrieveCheckoutSession')->times($times)->with($session->id)->andReturn($session);
        $this->app->instance(StripeCheckoutClient::class, $mock);
    }

    /** @param array<string, mixed> $override */
    private function makeSession(Payment $payment, string $id, string $intent, array $override = []): Session
    {
        return Session::constructFrom(array_merge([
            'id' => $id,
            'mode' => 'payment',
            'payment_status' => 'paid',
            'amount_total' => $payment->amount,
            'currency' => $payment->currency,
            'client_reference_id' => $payment->id,
            'payment_intent' => $intent,
            'metadata' => ['payment_id' => $payment->id],
        ], $override));
    }
}
