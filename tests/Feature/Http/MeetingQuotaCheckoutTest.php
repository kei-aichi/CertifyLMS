<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\StripeCheckoutClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

class MeetingQuotaCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_only_published_packs_at_or_above_minimum_are_listed(): void
    {
        $student = User::factory()->student()->create();
        $valid = MeetingPack::factory()->published()->withPrice(50)->create();
        MeetingPack::factory()->published()->withPrice(49)->create();
        MeetingPack::factory()->published()->withPrice(0)->create();
        MeetingPack::factory()->draft()->withPrice(100)->create();
        MeetingPack::factory()->archived()->withPrice(100)->create();

        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.select'));

        $response->assertOk()->assertViewHas('plans', function ($plans) use ($valid): bool {
            return $plans->pluck('id')->all() === [$valid->id];
        });
    }

    public function test_only_in_progress_student_can_access_checkout(): void
    {
        foreach ([
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->student()->graduated()->create(),
        ] as $user) {
            $this->actingAs($user)->get(route('meeting-quota.checkout.select'))->assertForbidden();
        }

        $this->get(route('meeting-quota.checkout.select'))->assertForbidden();
    }

    public function test_create_checkout_saves_snapshot_and_redirects_to_stripe(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->create([
            'name' => '購入時のパック',
            'price' => 500,
            'meeting_count' => 3,
        ]);
        $session = Session::constructFrom(['id' => 'cs_test_step2', 'url' => 'https://checkout.stripe.test/cs_test_step2']);
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('createCheckoutSession')->once()->withArgs(function (array $parameters, string $key) use ($pack, $student, &$paymentId): bool {
            $paymentId = $parameters['metadata']['payment_id'];
            return $key === 's-a-03-payment:'.$paymentId
                && $parameters['mode'] === 'payment'
                && $parameters['payment_method_types'] === ['card']
                && $parameters['line_items'][0]['price_data']['currency'] === 'jpy'
                && $parameters['line_items'][0]['price_data']['unit_amount'] === 500
                && $parameters['line_items'][0]['price_data']['product_data']['name'] === '購入時のパック'
                && $parameters['line_items'][0]['quantity'] === 1
                && $parameters['client_reference_id'] === $paymentId
                && str_contains($parameters['success_url'], 'session_id=%7BCHECKOUT_SESSION_ID%7D')
                && str_contains($parameters['cancel_url'], 'canceled=1');
        })->andReturn($session);
        $this->app->instance(StripeCheckoutClient::class, $mock);

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
            'amount' => 1,
            'quantity' => 999,
            'currency' => 'usd',
            'meeting_pack_name_snapshot' => '偽のパック名',
            'user_id' => User::factory()->student()->create()->id,
        ]);

        $response->assertRedirect($session->url);
        $payment = Payment::query()->firstOrFail();
        $this->assertSame($student->id, $payment->user_id);
        $this->assertSame($pack->id, $payment->meeting_pack_id);
        $this->assertSame('購入時のパック', $payment->meeting_pack_name_snapshot);
        $this->assertSame(500, $payment->amount);
        $this->assertSame(3, $payment->quantity);
        $this->assertSame('jpy', $payment->currency);
        $this->assertSame('cs_test_step2', $payment->stripe_checkout_session_id);
        $this->assertSame('pending', $payment->status->value);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unpublished_or_below_minimum_pack_is_rejected_on_post(): void
    {
        $student = User::factory()->student()->create();
        foreach ([
            MeetingPack::factory()->published()->withPrice(49)->create(),
            MeetingPack::factory()->draft()->withPrice(500)->create(),
            MeetingPack::factory()->archived()->withPrice(500)->create(),
        ] as $pack) {
            $this->actingAs($student)->postJson(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
                ->assertStatus(422);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stripe_api_failure_keeps_pending_payment_without_quota_transaction(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->withPrice(500)->create();
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('createCheckoutSession')->once()->andThrow(new \RuntimeException('Stripe timeout'));
        $this->app->instance(StripeCheckoutClient::class, $mock);

        $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
        ])->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error', '決済画面を開始できませんでした。時間をおいて再度お試しください。');

        $this->get(route('meeting-quota.checkout.select'))
            ->assertSee('決済画面を開始できませんでした。')
            ->assertDontSee('Stripe timeout');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertNull($payment->stripe_checkout_session_id);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_missing_session_id_keeps_pending_payment_and_shows_safe_error(): void
    {
        $this->assertInvalidStripeSessionResponse(Session::constructFrom(['id' => null, 'url' => 'https://checkout.stripe.test/session']));
    }

    public function test_missing_checkout_url_keeps_pending_payment_and_shows_safe_error(): void
    {
        $this->assertInvalidStripeSessionResponse(Session::constructFrom(['id' => 'cs_test_missing_url', 'url' => null]));
    }

    public function test_session_id_save_failure_keeps_pending_snapshot(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->withPrice(500)->create(['name' => '保存失敗前の名称']);
        $session = Session::constructFrom(['id' => 'cs_test_save_failure', 'url' => 'https://checkout.stripe.test/cs_test_save_failure']);
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('createCheckoutSession')->once()->andReturn($session);
        $this->app->instance(StripeCheckoutClient::class, $mock);
        Event::listen('eloquent.updating: '.Payment::class, static function (Payment $payment): void {
            if ($payment->isDirty('stripe_checkout_session_id')) {
                throw new \RuntimeException('session id persistence failed');
            }
        });

        $this->actingAs($student)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'));

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertNull($payment->stripe_checkout_session_id);
        $this->assertSame('保存失敗前の名称', $payment->meeting_pack_name_snapshot);
        $this->assertSame($student->id, $payment->user_id);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_deleted_pack_id_is_rejected_without_payment_or_stripe_call(): void
    {
        $student = User::factory()->student()->create();
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('createCheckoutSession')->never();
        $this->app->instance(StripeCheckoutClient::class, $mock);

        $this->actingAs($student)->postJson(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
        ])->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
    }

    private function assertInvalidStripeSessionResponse(Session $session): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->withPrice(500)->create();
        $mock = Mockery::mock(StripeCheckoutClient::class);
        $mock->shouldReceive('createCheckoutSession')->once()->andReturn($session);
        $this->app->instance(StripeCheckoutClient::class, $mock);

        $this->actingAs($student)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'));

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertNull($payment->stripe_checkout_session_id);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_success_entry_requires_valid_identifiers_and_never_shows_completed(): void
    {
        $student = User::factory()->student()->create();
        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.success', [
            'payment' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
            'session_id' => 'cs_test_pending',
        ]));

        $response->assertOk()->assertViewIs('meeting-quota.success-pending')
            ->assertSee('決済結果を確認しています')
            ->assertDontSee('お支払いが完了しました');
    }
}
