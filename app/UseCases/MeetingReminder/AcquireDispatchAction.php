<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AcquireDispatchAction
{
    private const TIMEZONE = 'Asia/Tokyo';

    private const WINDOWS = ['eve', 'one_hour_before'];

    private const DISPATCH_UNIQUE_INDEX = 'meeting_reminder_dispatches_meeting_window_recipient_unique';

    public function __invoke(
        string $meetingId,
        string $window,
        string $recipientId,
    ): AcquireDispatchResult {
        if (! in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('Invalid meeting reminder window.');
        }

        return DB::transaction(function () use ($meetingId, $window, $recipientId): AcquireDispatchResult {
            $meeting = Meeting::query()
                ->whereKey($meetingId)
                ->lockForUpdate()
                ->first();

            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) {
                return AcquireDispatchResult::notEligibleResult();
            }

            $now = CarbonImmutable::now(self::TIMEZONE);
            $scheduledAt = CarbonImmutable::instance($meeting->scheduled_at)
                ->setTimezone(self::TIMEZONE);

            if ($scheduledAt->lessThanOrEqualTo($now) || ! $this->isWithinWindow($now, $scheduledAt, $window)) {
                return AcquireDispatchResult::notEligibleResult();
            }

            $role = match ($recipientId) {
                $meeting->student_id => UserRole::Student,
                $meeting->coach_id => UserRole::Coach,
                default => null,
            };

            if ($role === null) {
                return AcquireDispatchResult::notEligibleResult();
            }

            $recipient = User::withTrashed()->find($recipientId);
            if (
                $recipient === null
                || $recipient->trashed()
                || $recipient->role !== $role
                || $recipient->status !== UserStatus::InProgress
            ) {
                return AcquireDispatchResult::notEligibleResult();
            }

            try {
                $dispatch = MeetingReminderDispatch::create([
                    'meeting_id' => $meeting->id,
                    'window' => $window,
                    'recipient_id' => $recipient->id,
                ]);
            } catch (QueryException $exception) {
                if ($this->isDispatchUniqueViolation($exception)) {
                    return AcquireDispatchResult::duplicateResult();
                }

                throw $exception;
            }

            return AcquireDispatchResult::acquiredResult($dispatch);
        });
    }

    private function isWithinWindow(CarbonImmutable $now, CarbonImmutable $scheduledAt, string $window): bool
    {
        if ($window === 'eve') {
            $windowStart = $now->startOfDay()->setTime(18, 0);
            $windowEnd = $windowStart->addMinute();

            return $now->greaterThanOrEqualTo($windowStart)
                && $now->lessThan($windowEnd)
                && $scheduledAt->greaterThanOrEqualTo($now->startOfDay()->addDay())
                && $scheduledAt->lessThan($now->startOfDay()->addDays(2));
        }

        $targetStart = $now->startOfMinute()->addHour();

        return $scheduledAt->greaterThanOrEqualTo($targetStart)
            && $scheduledAt->lessThan($targetStart->addMinute());
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
