<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Carbon;
use RuntimeException;

class GoogleCalendarService
{
    public function __construct(private readonly GoogleOAuthService $oauth) {}

    /** @return list<array{start: Carbon, end: Carbon}> */
    public function freeBusy(?GoogleCredential $credential, Carbon $from, Carbon $to): array
    {
        if ($credential === null) {
            return [];
        }

        $calendar = $this->calendarClient($credential);
        $request = new FreeBusyRequest([
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'items' => [new FreeBusyRequestItem(['id' => $credential->calendar_id])],
        ]);
        try {
            $response = $calendar->freebusy->query($request);
            $calendars = $response->getCalendars();
            if (! is_array($calendars) || ! array_key_exists($credential->calendar_id, $calendars)) {
                throw new RuntimeException('Google Calendar FreeBusy response was invalid.');
            }

            $calendarResponse = $calendars[$credential->calendar_id];
            $busy = $calendarResponse->getBusy();
            if (! is_array($busy)) {
                throw new RuntimeException('Google Calendar FreeBusy response was invalid.');
            }

            $result = [];
            foreach ($busy as $period) {
                $startValue = $period->getStart();
                $endValue = $period->getEnd();
                if (! is_string($startValue) || $startValue === '' || ! is_string($endValue) || $endValue === '') {
                    throw new RuntimeException('Google Calendar FreeBusy response was invalid.');
                }

                try {
                    $start = Carbon::parse($startValue);
                    $end = Carbon::parse($endValue);
                } catch (\Throwable $exception) {
                    throw new RuntimeException('Google Calendar FreeBusy response was invalid.', 0, $exception);
                }

                if ($start->greaterThanOrEqualTo($end)) {
                    throw new RuntimeException('Google Calendar FreeBusy response was invalid.');
                }

                $result[] = ['start' => $start, 'end' => $end];
            }

            return $result;
        } catch (GoogleServiceException $exception) {
            throw new RuntimeException('Google Calendar FreeBusy request failed.', 0, $exception);
        }
    }

    public function createEvent(GoogleCredential $credential, Meeting $meeting): string
    {
        $start = $meeting->scheduled_at;
        $end = $start->copy()->addHour();
        $event = new Event([
            'summary' => '面談',
            'description' => (string) $meeting->meeting_url_snapshot,
            'start' => new EventDateTime([
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $start->getTimezone()->getName(),
            ]),
            'end' => new EventDateTime([
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $end->getTimezone()->getName(),
            ]),
        ]);

        $created = $this->calendarClient($credential)->events->insert($credential->calendar_id, $event);
        $id = $created->getId();
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Google Calendar event ID was not returned.');
        }

        return $id;
    }

    /** Returns false without calling Google when the connection is no longer current. */
    public function deleteEvent(?GoogleCredential $credential, string $connectionId, string $eventId): bool
    {
        if ($credential === null || $credential->getKey() !== $connectionId || $eventId === '') {
            return false;
        }

        try {
            $this->calendarClient($credential)->events->delete($credential->calendar_id, $eventId);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw new RuntimeException('Google Calendar event deletion failed.', 0, $exception);
            }
        }

        return true;
    }

    protected function calendarClient(GoogleCredential $credential): Calendar
    {
        $client = new Client;
        $client->setAccessToken($this->oauth->accessToken($credential));

        return new Calendar($client);
    }
}
