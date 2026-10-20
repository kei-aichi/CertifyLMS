<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Exceptions\MeetingQuota\CheckoutFulfillmentException;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\StripeCheckoutClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_signed_completed_event_fulfills_without_auth_or_csrf(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $session = $this->sessionPayload($payment, 'cs_webhook', 'pi_webhook');
        $this->mockStripe($session);

        $response = $this->signedPost($this->eventPayload('checkout.session.completed', $session));

        $response->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_signature_errors_and_invalid_json_are_rejected(): void
    {
        $payload = json_encode($this->eventPayload('checkout.session.completed', []), JSON_THROW_ON_ERROR);
        $this->postJson('/webhooks/stripe', json_decode($payload, true))->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad')->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'bad',
        ], $payload)->assertStatus(400);
    }

    public function test_expired_and_unknown_events_are_acknowledged_without_changes(): void
    {
        $payment = Payment::factory()->create();
        $this->signedPost($this->eventPayload('checkout.session.expired', ['id' => 'cs_expired']))->assertOk();
        $this->signedPost($this->eventPayload('customer.created', ['id' => 'cus_unknown']))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unpaid_event_is_acknowledged_without_fulfillment(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $session = $this->sessionPayload($payment, 'cs_unpaid', 'pi_unpaid', ['payment_status' => 'unpaid']);
        $this->mockStripe($session);
        $this->signedPost($this->eventPayload('checkout.session.completed', $session))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_retryable_is_500_and_permanent_mismatch_is_200(): void
    {
        $payment = Payment::factory()->create();
        $payload = ['id' => 'cs_retry', 'metadata' => ['payment_id' => $payment->id]];
        $stripe = Mockery::mock(StripeCheckoutClient::class);
        $stripe->shouldReceive('retrieveCheckoutSession')->andThrow(new \RuntimeException('temporary'));
        $this->app->instance(StripeCheckoutClient::class, $stripe);
        $this->signedPost($this->eventPayload('checkout.session.completed', $payload))->assertStatus(500);

        $invalid = Session::constructFrom(['id' => 'cs_retry', 'mode' => 'payment', 'payment_status' => 'paid', 'metadata' => ['payment_id' => 'other']]);
        $stripe = Mockery::mock(StripeCheckoutClient::class);
        $stripe->shouldReceive('retrieveCheckoutSession')->andReturn($invalid);
        $this->app->instance(StripeCheckoutClient::class, $stripe);
        $this->signedPost($this->eventPayload('checkout.session.completed', $payload))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_repeated_event_and_success_screen_are_idempotent(): void
    {
        $payment = Payment::factory()->create(['amount' => 500, 'quantity' => 3]);
        $session = $this->sessionPayload($payment, 'cs_repeat', 'pi_repeat');
        $this->mockStripe($session, 3);
        $event = $this->eventPayload('checkout.session.completed', $session);
        $this->signedPost($event)->assertOk();
        $this->signedPost($event)->assertOk();
        $this->actingAs($payment->user)->get(route('meeting-quota.checkout.success', ['payment' => $payment->id, 'session_id' => 'cs_repeat']))->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    /** @param array<string,mixed> $session */
    private function signedPost(array $event): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signature($payload),
        ], $payload);
    }

    private function signature(string $payload): string
    {
        $timestamp = time();
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
    }

    /** @param array<string,mixed> $object */
    private function eventPayload(string $type, array $object): array
    {
        return ['id' => 'evt_'.bin2hex(random_bytes(5)), 'object' => 'event', 'type' => $type,
            'api_version' => '2024-06-20', 'created' => time(), 'data' => ['object' => $object]];
    }

    /** @return array<string,mixed> */
    private function sessionPayload(Payment $payment, string $id, string $intent, array $extra = []): array
    {
        return array_merge(['id' => $id, 'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => $payment->amount,
            'currency' => $payment->currency, 'client_reference_id' => $payment->id, 'payment_intent' => $intent,
            'metadata' => ['payment_id' => $payment->id]], $extra);
    }

    /** @param array<string,mixed> $payload */
    private function mockStripe(array $payload, int $times = 1): void
    {
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('retrieveCheckoutSession')->times($times)->andReturn(Session::constructFrom($payload));
        $this->app->instance(StripeCheckoutClient::class, $mock);
    }
}
