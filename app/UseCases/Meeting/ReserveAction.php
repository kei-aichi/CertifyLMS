<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReserveAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;
        $initialCandidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);
        $googleBusyCoachIds = $this->googleBusyCoachIds($initialCandidates, $scheduledAt);

        $meeting = DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic, $googleBusyCoachIds): Meeting {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);
            $candidateIds = $this->findAvailableCoaches($enrollment->certification, $scheduledAt)
                ->pluck('id')->sort()->reject(fn (string $id): bool => $googleBusyCoachIds->contains($id))->values();

            if ($candidateIds->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $lockedCandidates = $candidateIds->map(fn (string $coachId): User => User::query()->whereKey($coachId)->lockForUpdate()->firstOrFail());
            $availableCandidates = $lockedCandidates->filter(fn (User $coach): bool => ! Meeting::query()
                ->where('coach_id', $coach->id)->where('scheduled_at', $scheduledAt)
                ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
                ->lockForUpdate()->exists())->values();

            if ($availableCandidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($availableCandidates);
            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'coach_id' => $coach->id,
                'student_id' => $student->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved->value,
                'topic' => $topic,
                'meeting_url_snapshot' => $coach->meeting_url,
                'google_connection_id' => $coach->googleCredential()->value('id'),
            ]);

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            DB::afterCommit(function () use ($meeting): void {
                $meeting->loadMissing('coach');
                $coach = $meeting->coach;
                if ($coach !== null && $coach->status === UserStatus::InProgress) {
                    $coach->notify(new MeetingReservedNotification($meeting));
                }
            });

            return $meeting->fresh();
        });

        $this->createGoogleEventAfterCommit($meeting);

        return $meeting;
    }

    /** @return Collection<int, User> */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->with('googleCredential')
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time): void {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt): void {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }

    private function googleBusyCoachIds(Collection $candidates, Carbon $scheduledAt): Collection
    {
        $from = $scheduledAt->copy()->startOfHour();
        $to = $from->copy()->addHour();
        $blocked = collect();
        foreach ($candidates as $coach) {
            $credential = $coach->googleCredential()->first();
            if ($credential === null) {
                continue;
            }
            try {
                $busy = $this->googleCalendar->freeBusy($credential, $from, $to);
                if (collect($busy)->contains(fn (array $period): bool => $scheduledAt->lt($period['end']) && $scheduledAt->copy()->addHour()->gt($period['start']))) {
                    $blocked->push((string) $coach->getKey());
                }
            } catch (\Throwable $exception) {
                Log::warning('Google Calendar FreeBusy failed during reservation; using LMS availability.', ['operation' => 'freebusy_reservation', 'coach_id' => $coach->getKey(), 'connection_id' => $credential->getKey(), 'error_class' => $exception::class]);
            }
        }

        return $blocked;
    }

    private function createGoogleEventAfterCommit(Meeting $meeting): void
    {
        if ($meeting->google_connection_id === null) {
            return;
        }

        $credential = $meeting->coach?->googleCredential;
        if ($credential === null || $credential->getKey() !== $meeting->google_connection_id) {
            return;
        }
        try {
            $eventId = $this->googleCalendar->createEvent($credential, $meeting);
            $updated = Meeting::query()->whereKey($meeting->getKey())->where('status', MeetingStatus::Reserved->value)
                ->where('google_connection_id', $credential->getKey())->whereNull('google_event_id')
                ->update(['google_event_id' => $eventId, 'google_event_created_at' => now()]);
            if ($updated === 0) {
                Log::warning('Google event created but Meeting was not updated.', ['operation' => 'event_create', 'meeting_id' => $meeting->getKey(), 'connection_id' => $credential->getKey(), 'error_class' => 'meeting_state_changed']);
                $this->compensateOrphanedEvent($meeting, $credential, $eventId);
            }
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar event creation failed.', ['operation' => 'event_create', 'meeting_id' => $meeting->getKey(), 'connection_id' => $credential->getKey(), 'error_class' => $exception::class]);
            $this->compensateOrphanedEvent($meeting, $credential, $eventId ?? null);
        }
    }

    private function compensateOrphanedEvent(Meeting $meeting, $credential, ?string $eventId): void
    {
        if ($eventId === null) {
            return;
        }
        $latest = Meeting::query()->find($meeting->getKey());
        if ($latest?->status !== MeetingStatus::Canceled || $latest->google_connection_id !== $credential->getKey()) {
            return;
        }
        $currentCredential = $latest->coach?->googleCredential;
        if ($currentCredential === null || $currentCredential->getKey() !== $credential->getKey()) {
            return;
        }
        try {
            $this->googleCalendar->deleteEvent($currentCredential, $credential->getKey(), $eventId);
        } catch (\Throwable $exception) {
            Log::warning('Google orphan event compensation failed.', ['operation' => 'event_compensation_delete', 'meeting_id' => $latest->getKey(), 'connection_id' => $credential->getKey(), 'error_class' => $exception::class]);
        }
    }
}
