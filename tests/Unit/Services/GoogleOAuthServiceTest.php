<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCredential;
use App\Models\User;
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
}
