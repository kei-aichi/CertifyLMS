<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Models\MeetingReminderDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class AcquireDispatchAction
{
    private const DISPATCH_UNIQUE_INDEX = 'meeting_reminder_dispatches_meeting_window_recipient_unique';

    public function __invoke(
        string $meetingId,
        string $window,
        string $recipientId,
        bool $queueable = false,
    ): AcquireDispatchResult {
        return DB::transaction(function () use ($meetingId, $window, $recipientId, $queueable): AcquireDispatchResult {
            $context = (new ResolveMeetingReminderRecipient)($meetingId, $window, $recipientId);
            if ($context === null) return AcquireDispatchResult::notEligibleResult();
            $meeting = $context->meeting;
            $recipient = $context->recipient;

            try {
                $attributes = [
                    'meeting_id' => $meeting->id,
                    'window' => $window,
                    'recipient_id' => $recipient->id,
                ];
                if ($queueable) {
                    $attributes += [
                        'legacy' => false,
                        'database_status' => 'pending',
                        'mail_status' => 'pending',
                    ];
                }
                $dispatch = MeetingReminderDispatch::create($attributes);
            } catch (QueryException $exception) {
                if ($this->isDispatchUniqueViolation($exception)) {
                    return AcquireDispatchResult::duplicateResult();
                }

                throw $exception;
            }

            return AcquireDispatchResult::acquiredResult($dispatch);
        });
    }

    private function isDispatchUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $driverCode = (string) ($errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        if ($driverCode === '1062') {
            return str_contains($message, self::DISPATCH_UNIQUE_INDEX);
        }

        return str_contains($message, 'unique constraint failed: meeting_reminder_dispatches.')
            && str_contains($message, 'meeting_id')
            && str_contains($message, 'window')
            && str_contains($message, 'recipient_id');
    }
}
