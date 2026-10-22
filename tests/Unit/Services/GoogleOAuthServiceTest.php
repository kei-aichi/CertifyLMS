<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleClientFactory;
use App\Services\GoogleOAuthService;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GoogleOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_expired_access_token_and_preserves_refresh_token(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create([
            'access_token' => 'expired',
            'refresh_token' => 'refresh',
            'expires_at' => now()->subMinute(),
        ]);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('fetchAccessTokenWithRefreshToken')->once()->with('refresh')->andReturn([
            'access_token' => 'new-access',
            'expires_in' => 1800,
        ]);
        $service = Mockery::mock(GoogleOAuthService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('client')->once()->andReturn($client);

        $this->assertSame('new-access', $service->accessToken($credential));
        $credential->refresh();
        $this->assertSame('new-access', $credential->access_token);
        $this->assertSame('refresh', $credential->refresh_token);
        $this->assertTrue($credential->expires_at->isFuture());
    }

    public function test_does_not_call_google_when_access_token_is_valid(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create([
            'expires_at' => now()->addHour(),
        ]);
        $service = Mockery::mock(GoogleOAuthService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldNotReceive('client');

        $this->assertSame($credential->access_token, $service->accessToken($credential));
    }

    public function test_factory_preserves_oauth_client_configuration(): void
    {
        config([
            'services.google_calendar.client_id' => 'client-id',
            'services.google_calendar.client_secret' => 'client-secret',
            'services.google_calendar.redirect_uri' => 'https://example.test/callback',
        ]);

        $client = app(GoogleClientFactory::class)->createOAuthClient();

        $this->assertSame('client-id', $client->getClientId());
        $this->assertSame('client-secret', $client->getClientSecret());
        $this->assertSame('https://example.test/callback', $client->getRedirectUri());
        $this->assertSame([
            'https://www.googleapis.com/auth/calendar.freebusy',
            'https://www.googleapis.com/auth/calendar.events.owned',
        ], $client->getScopes());
    }

    public function test_factory_can_be_explicitly_injected(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('setState')->once()->with('state');
        $client->shouldReceive('createAuthUrl')->once()->andReturn('https://example.test/oauth');
        $factory = Mockery::mock(GoogleClientFactory::class);
        $factory->shouldReceive('createOAuthClient')->once()->andReturn($client);

        $service = new GoogleOAuthService($factory);

        $this->assertSame('https://example.test/oauth', $service->authorizationUrl('state'));
    }

    public function test_factory_can_be_replaced_by_container_binding(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('setState')->once()->with('state');
        $client->shouldReceive('createAuthUrl')->once()->andReturn('https://example.test/oauth');
        $factory = Mockery::mock(GoogleClientFactory::class);
        $factory->shouldReceive('createOAuthClient')->once()->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $service = new GoogleOAuthService;

        $this->assertSame('https://example.test/oauth', $service->authorizationUrl('state'));
    }
}
