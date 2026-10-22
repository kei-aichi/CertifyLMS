<?php

declare(strict_types=1);

namespace App\Services;

use Google\Client;

class GoogleClientFactory
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.events.owned',
    ];

    public function createOAuthClient(): Client
    {
        $client = $this->baseClient();
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes(self::SCOPES);

        return $client;
    }

    public function createCalendarClient(string $accessToken): Client
    {
        $client = $this->baseClient();
        $client->setAccessToken($accessToken);

        return $client;
    }

    private function baseClient(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google_calendar.client_id'));
        $client->setClientSecret((string) config('services.google_calendar.client_secret'));
        $client->setRedirectUri((string) config('services.google_calendar.redirect_uri'));

        return $client;
    }
}
