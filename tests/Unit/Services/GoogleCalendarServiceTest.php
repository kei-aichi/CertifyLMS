<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\GoogleClientFactory;
use App\Services\GoogleOAuthService;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconnected_coach_does_not_call_google_for_free_busy(): void
    {
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $oauth->shouldNotReceive('accessToken');

        $service = new GoogleCalendarService($oauth);

        $this->assertSame([], $service->freeBusy(null, now(), now()->addHour()));
    }

    public function test_free_busy_maps_google_periods(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $period = Mockery::mock();
        $period->shouldReceive('getStart')->andReturn('2026-10-17T10:00:00+09:00');
        $period->shouldReceive('getEnd')->andReturn('2026-10-17T11:00:00+09:00');
        $calendarList = Mockery::mock();
        $calendarList->shouldReceive('getBusy')->andReturn([$period]);
        $calendar = Mockery::mock(Calendar::class);
        $calendar->freebusy = Mockery::mock();
        $calendar->freebusy->shouldReceive('query')->once()->andReturnUsing(function () use ($calendarList, $credential) {
            $response = Mockery::mock();
            $response->shouldReceive('getCalendars')->andReturn([$credential->calendar_id => $calendarList]);

            return $response;
        });
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $service = Mockery::mock(GoogleCalendarService::class, [$oauth])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('calendarClient')->once()->andReturn($calendar);

        $busy = $service->freeBusy($credential, Carbon::parse('2026-10-17 09:00:00'), Carbon::parse('2026-10-17 12:00:00'));

        $this->assertSame('2026-10-17 10:00:00', $busy[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-17 11:00:00', $busy[0]['end']->format('Y-m-d H:i:s'));
    }

    public function test_free_busy_returns_empty_array_for_empty_busy_response(): void
    {
        [$service, $credential] = $this->freeBusyServiceWithCalendar([]);

        $this->assertSame([], $service->freeBusy($credential, now(), now()->addHour()));
    }

    public function test_free_busy_rejects_missing_calendar(): void
    {
        [$service, $credential] = $this->freeBusyServiceWithCalendar([], calendars: []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar FreeBusy response was invalid.');
        $service->freeBusy($credential, now(), now()->addHour());
    }

    /** @dataProvider invalidBusyPeriods */
    public function test_free_busy_rejects_invalid_busy_period(string $start, string $end): void
    {
        [$service, $credential] = $this->freeBusyServiceWithCalendar([
            [$start, $end],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar FreeBusy response was invalid.');
        $service->freeBusy($credential, now(), now()->addHour());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidBusyPeriods(): array
    {
        return [
            'missing start' => ['', '2026-10-17T11:00:00+09:00'],
            'missing end' => ['2026-10-17T10:00:00+09:00', ''],
            'invalid start' => ['invalid', '2026-10-17T11:00:00+09:00'],
            'start after end' => ['2026-10-17T12:00:00+09:00', '2026-10-17T11:00:00+09:00'],
            'start equals end' => ['2026-10-17T11:00:00+09:00', '2026-10-17T11:00:00+09:00'],
        ];
    }

    public function test_free_busy_converts_google_api_error_to_safe_exception(): void
    {
        [$service, $credential] = $this->freeBusyServiceWithCalendar([], apiException: new GoogleServiceException('sensitive response', 503));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar FreeBusy request failed.');
        $service->freeBusy($credential, now(), now()->addHour());
    }

    /** @dataProvider googleApiErrorStatuses */
    public function test_free_busy_converts_google_api_error_statuses_to_safe_exception(int $status): void
    {
        [$service, $credential] = $this->freeBusyServiceWithCalendar([], apiException: new GoogleServiceException('upstream', $status));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar FreeBusy request failed.');
        $service->freeBusy($credential, now(), now()->addHour());
    }

    public static function googleApiErrorStatuses(): array
    {
        return [[401], [403], [429], [500]];
    }

    public function test_create_event_uses_fixed_title_url_and_times(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $meeting = Meeting::factory()->create([
            'coach_id' => $credential->coach_id,
            'scheduled_at' => Carbon::parse('2026-10-17 10:00:00'),
            'meeting_url_snapshot' => 'https://example.test/meeting',
        ]);
        $created = Mockery::mock();
        $created->shouldReceive('getId')->andReturn('event-123');
        $events = Mockery::mock();
        $events->shouldReceive('insert')->once()->withArgs(function ($calendarId, Event $event): bool {
            return $calendarId === 'primary'
                && $event->getSummary() === '面談'
                && $event->getDescription() === 'https://example.test/meeting'
                && $event->getStart() instanceof EventDateTime
                && $event->getEnd() instanceof EventDateTime;
        })->andReturn($created);
        $calendar = Mockery::mock(Calendar::class);
        $calendar->events = $events;
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $service = Mockery::mock(GoogleCalendarService::class, [$oauth])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('calendarClient')->once()->andReturn($calendar);

        $this->assertSame('event-123', $service->createEvent($credential, $meeting));
    }

    public function test_create_event_rejects_response_without_event_id(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $meeting = Meeting::factory()->forCoach($credential->coach)->create();
        $created = Mockery::mock();
        $created->shouldReceive('getId')->once()->andReturn(null);
        $events = Mockery::mock();
        $events->shouldReceive('insert')->once()->andReturn($created);
        $calendar = Mockery::mock(Calendar::class);
        $calendar->events = $events;
        $service = Mockery::mock(GoogleCalendarService::class, [Mockery::mock(GoogleOAuthService::class)])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('calendarClient')->once()->andReturn($calendar);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar event ID was not returned.');
        $service->createEvent($credential, $meeting);
    }

    public function test_delete_event_converts_non_404_google_error(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $events = Mockery::mock();
        $events->shouldReceive('delete')->once()->andThrow(new GoogleServiceException('forbidden', 403));
        $calendar = Mockery::mock(Calendar::class);
        $calendar->events = $events;
        $service = Mockery::mock(GoogleCalendarService::class, [Mockery::mock(GoogleOAuthService::class)])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('calendarClient')->once()->andReturn($calendar);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Calendar event deletion failed.');
        $service->deleteEvent($credential, $credential->getKey(), 'event-123');
    }

    public function test_delete_event_skips_old_connection_and_treats_missing_event_as_success(): void
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $service = Mockery::mock(GoogleCalendarService::class, [$oauth])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldNotReceive('calendarClient');

        $this->assertFalse($service->deleteEvent($credential, 'old-connection', 'event-123'));
    }

    public function test_factory_preserves_calendar_access_token_configuration(): void
    {
        $client = app(GoogleClientFactory::class)->createCalendarClient('access-token');

        $this->assertSame(['access_token' => 'access-token'], $client->getAccessToken());
    }

    /** @return array{GoogleCalendarService, GoogleCredential} */
    private function freeBusyServiceWithCalendar(array $periods, ?array $calendars = null, ?GoogleServiceException $apiException = null): array
    {
        $credential = GoogleCredential::factory()->for(User::factory()->coach()->create(), 'coach')->create();
        $calendarResponse = Mockery::mock();
        $calendarResponse->shouldReceive('getBusy')->andReturn(array_map(function (array $period) {
            $busy = Mockery::mock();
            $busy->shouldReceive('getStart')->andReturn($period[0]);
            $busy->shouldReceive('getEnd')->andReturn($period[1]);

            return $busy;
        }, $periods));
        $calendar = Mockery::mock(Calendar::class);
        $calendar->freebusy = Mockery::mock();
        $query = $calendar->freebusy->shouldReceive('query')->once();
        if ($apiException !== null) {
            $query->andThrow($apiException);
        } else {
            $query->andReturnUsing(function () use ($calendarResponse, $credential, $calendars) {
                $response = Mockery::mock();
                $response->shouldReceive('getCalendars')->andReturn($calendars ?? [$credential->calendar_id => $calendarResponse]);

                return $response;
            });
        }
        $oauth = Mockery::mock(GoogleOAuthService::class);
        $service = Mockery::mock(GoogleCalendarService::class, [$oauth])->makePartial();
        $service->shouldAllowMockingProtectedMethods()->shouldReceive('calendarClient')->once()->andReturn($calendar);

        return [$service, $credential];
    }
}
