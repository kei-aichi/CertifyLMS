<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Throwable;

final class SendReminderAction
{
    private const TIMEZONE = 'Asia/Tokyo';

    public function __construct(private readonly AcquireDispatchAction $acquireDispatch) {}

    public function __invoke(string $meetingId, string $window, string $recipientId): SendReminderResult
    {
        $acquisition = ($this->acquireDispatch)($meetingId, $window, $recipientId);

        if (! $acquisition->acquired()) {
            return new SendReminderResult($acquisition->status);
        }

        $notificationId = (string) Str::uuid();
        $meeting = Meeting::query()->with(['coach', 'student'])->find($meetingId);
        $recipient = User::query()->find($recipientId);

        if ($meeting === null || $recipient === null) {
            return new SendReminderResult('acquired', 'skipped', 'skipped', $notificationId);
        }

        $notification = new MeetingReminderNotification($meeting, $window);
        $notification->id = $notificationId;
        $databaseStatus = $this->sendChannel(
            $meetingId,
            $window,
            $recipientId,
            'database',
            $recipient,
            $notification,
        );
        $mailStatus = $this->sendChannel(
            $meetingId,
            $window,
            $recipientId,
            'mail',
            $recipient,
            $notification,
        );

        return new SendReminderResult('acquired', $databaseStatus, $mailStatus, $notificationId);
    }

    private function sendChannel(
        string $meetingId,
        string $window,
        string $recipientId,
        string $channel,
        User $recipient,
        MeetingReminderNotification $notification,
    ): string {
        try {
            $meeting = Meeting::query()->with(['coach', 'student'])->find($meetingId);

            if (! $this->canStartChannel($meeting)) {
                $this->logChannel($meetingId, $window, $recipientId, $channel, 'skipped_after_start');

                return 'skipped_after_start';
            }

            if ($recipient === null) {
                $this->logChannel($meetingId, $window, $recipientId, $channel, 'skipped');

                return 'skipped';
            }

            NotificationFacade::sendNow($recipient, $notification, [$channel]);
            $this->logChannel($meetingId, $window, $recipientId, $channel, 'success');

            return 'success';
        } catch (Throwable $exception) {
            Log::error('Meeting reminder channel failed.', [
                'meeting_id' => $meetingId,
                'window' => $window,
                'recipient_id' => $recipientId,
                'channel' => $channel,
                'stage' => 'send',
                'status' => 'failed',
                'exception' => $exception::class,
            ]);

            return 'failed';
        }
    }

    private function canStartChannel(?Meeting $meeting): bool
    {
        if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) {
            return false;
        }

        $now = CarbonImmutable::now(self::TIMEZONE);
        $scheduledAt = CarbonImmutable::instance($meeting->scheduled_at)
            ->setTimezone(self::TIMEZONE);

        return $scheduledAt->greaterThan($now);
    }

    private function logChannel(
        string $meetingId,
        string $window,
        string $recipientId,
        string $channel,
        string $status,
    ): void {
        Log::info('Meeting reminder channel processed.', [
            'meeting_id' => $meetingId,
            'window' => $window,
            'recipient_id' => $recipientId,
            'channel' => $channel,
            'stage' => 'send',
            'status' => $status,
        ]);
    }
}
