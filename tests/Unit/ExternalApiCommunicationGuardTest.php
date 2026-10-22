<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\GoogleClientFactory;
use App\Services\GoogleOAuthService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\FailingGoogleHttpClient;
use Tests\Support\FailingStripeHttpClient;
use Tests\TestCase;

#[Group('external-api-guard')]
class ExternalApiCommunicationGuardTest extends TestCase
{
    public function test_unmatched_laravel_http_request_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        Http::get('https://unmatched.test/request');
    }

    public function test_google_oauth_client_uses_the_injected_blocking_http_client(): void
    {
        $factory = new GoogleClientFactory(FailingGoogleHttpClient::make());

        $this->expectException(\RuntimeException::class);
        (new GoogleOAuthService($factory))->exchangeCode('code');
    }

    public function test_google_calendar_client_uses_the_injected_blocking_http_client(): void
    {
        $factory = new GoogleClientFactory(FailingGoogleHttpClient::make());
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $oauth = $this->mock(GoogleOAuthService::class);
        $oauth->shouldReceive('accessToken')->once()->andReturn('access-token');

        $this->expectException(\RuntimeException::class);
        (new GoogleCalendarService($oauth, $factory))->freeBusy($credential, now(), now()->addHour());
    }

    public function test_stripe_sdk_uses_the_injected_blocking_http_client(): void
    {
        ApiRequestor::setHttpClient(new FailingStripeHttpClient);

        $this->expectException(ApiConnectionException::class);
        ApiRequestor::httpClient()->request('get', 'https://api.stripe.com/v1/test', [], [], false);
    }
}
