<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\MeetingQuotaTransactionMismatchException;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Jobs\Meeting\DispatchMeetingCanceledNotifications;
use App\Support\Notifications\MeetingCancellationSnapshot;
use App\Services\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Meeting $meeting, User $actor): Meeting
    {
        $coachId = $meeting->coach_id;
        $canceledMeeting = DB::transaction(function () use ($meeting, $actor, $coachId): Meeting {
            User::query()->whereKey($coachId)->lockForUpdate()->firstOrFail();

            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->coach_id !== $coachId || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $transaction = $locked->meeting_quota_transaction_id === null
                ? null
                : MeetingQuotaTransaction::query()->whereKey($locked->meeting_quota_transaction_id)->first();

            if ($transaction === null
                || $transaction->user_id !== $locked->student_id
                || $transaction->related_meeting_id !== $locked->id
                || $transaction->type !== MeetingQuotaTransactionType::Consumed
                || $transaction->amount !== -1) {
                throw new MeetingQuotaTransactionMismatchException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($locked->student, $locked->id);

            $notificationSnapshot = MeetingCancellationSnapshot::fromMeeting($locked, $actor);
            DB::afterCommit(function () use ($notificationSnapshot): void {
                DispatchMeetingCanceledNotifications::dispatch($notificationSnapshot);
            });

            return $locked->fresh();
        });

        $this->deleteGoogleEventAfterCommit($canceledMeeting);

        return $canceledMeeting;
    }

    private function deleteGoogleEventAfterCommit(Meeting $meeting): void
    {
        if ($meeting->google_event_id === null || $meeting->google_connection_id === null) {
            return;
        }
        $credential = $meeting->coach?->googleCredential;
        if ($credential === null) {
            return;
        }
        $apiConnectionId = (string) $meeting->google_connection_id;
        $eventId = (string) $meeting->google_event_id;
        try {
            $this->googleCalendar->deleteEvent($credential, $apiConnectionId, $eventId);
            $latest = Meeting::query()->with('coach.googleCredential')->find($meeting->getKey());
            $currentConnectionId = $latest?->coach?->googleCredential?->getKey();
            if ($latest === null || $currentConnectionId !== $apiConnectionId) {
                Log::warning('Google event deletion completed after connection state changed.', [
                    'operation' => 'event_delete_state_changed',
                    'meeting_id' => $meeting->getKey(),
                    'connection_id' => $apiConnectionId,
                    'error_class' => 'connection_state_changed',
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar event deletion failed.', [
                'operation' => 'event_delete',
                'meeting_id' => $meeting->getKey(),
                'connection_id' => $meeting->google_connection_id,
                'error_class' => $exception::class,
            ]);
        }
    }
}
