<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCredential;
use Google\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GoogleOAuthService
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.events.owned',
    ];

    public function authorizationUrl(string $state): string
    {
        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code): array
    {
        $token = $this->client()->fetchAccessTokenWithAuthCode($code);
        if (! is_array($token) || isset($token['error'])) {
            throw new RuntimeException('Google OAuth token exchange failed.');
        }

        return $token;
    }

    public function accessToken(GoogleCredential $credential): string
    {
        if ($credential->expires_at?->isFuture()) {
            return (string) $credential->access_token;
        }

        return Cache::lock('google-oauth-refresh:'.$credential->getKey(), 30)->block(5, function () use ($credential): string {
            $credential->refresh();
            if ($credential->expires_at?->isFuture()) {
                return (string) $credential->access_token;
            }

            $token = $this->client()->fetchAccessTokenWithRefreshToken((string) $credential->refresh_token);
            if (! is_array($token) || isset($token['error']) || empty($token['access_token'])) {
                throw new RuntimeException('Google OAuth token refresh failed.');
            }

            $credential->forceFill([
                'access_token' => $token['access_token'],
                'expires_at' => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            ])->save();

            return (string) $token['access_token'];
        });
    }

    protected function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google_calendar.client_id'));
        $client->setClientSecret((string) config('services.google_calendar.client_secret'));
        $client->setRedirectUri((string) config('services.google_calendar.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes(self::SCOPES);

        return $client;
    }
}
