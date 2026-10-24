<?php

declare(strict_types=1);

namespace App\Jobs\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Services\Notifications\NotificationAttemptTracker;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class MeetingReminderDatabaseNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public function __construct(
        public readonly string $dispatchId,
        public readonly string $token,
        public readonly ?string $queueAttemptUuid = null,
        public readonly int $cycle = 0,
    ) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('meeting-reminder-db:'.$this->dispatchId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $reference = MeetingReminderDispatch::query()->find($this->dispatchId);
        if ($reference === null) return;
        if ($this->queueAttemptUuid !== null && ! MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $this->dispatchId)->where('channel', 'database')->where('dispatch_token', $this->token)->exists()) return;

        $context = DB::transaction(function () use ($reference): ?array {
            $meeting = Meeting::query()->with(['coach', 'student'])->lockForUpdate()->find($reference->meeting_id);
            $dispatch = MeetingReminderDispatch::query()->lockForUpdate()->find($this->dispatchId);
            $recipient = $dispatch ? User::query()->find($dispatch->recipient_id) : null;
            if ($dispatch === null || $dispatch->meeting_id !== $meeting?->id || $dispatch->isLegacy()
                || $dispatch->database_status !== 'processing'
                || $dispatch->database_dispatch_token !== $this->token
                || ! $this->eligible($meeting, $recipient, (string) $dispatch->window)) return null;
            return [$dispatch, $meeting, $recipient];
        });
        if ($context === null) return;
        [$dispatch, $meeting, $recipient] = $context;
        $notificationId = self::notificationId($dispatch->meeting_id, $dispatch->window, $dispatch->recipient_id);
        $notification = new MeetingReminderNotification($meeting, $dispatch->window);
        $expected = $notification->toArray($recipient);
        $existing = $recipient->notifications()->whereKey($notificationId)->first();
        if ($existing !== null && $this->samePayload($existing->data, $expected)) {
            $this->markSucceeded($dispatch->id);
            return;
        }
        if ($existing !== null) throw new RuntimeException('Existing reminder notification has different payload.');

        $attempt = app(NotificationAttemptTracker::class)->increment('meeting_reminder:'.$dispatch->meeting_id.':'.$dispatch->window, $dispatch->recipient_id, 'database', $this->cycle);
        if ($attempt > 3) {
            $this->markFailed($dispatch->id);
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        try {
            DB::transaction(function () use ($recipient, $notification, $notificationId, $dispatch): void {
                $meeting = Meeting::query()->lockForUpdate()->find($dispatch->meeting_id);
                $locked = MeetingReminderDispatch::query()->lockForUpdate()->find($dispatch->id);
                $lockedRecipient = $locked ? User::query()->lockForUpdate()->find($locked->recipient_id) : null;
                if ($locked === null || $meeting === null || $lockedRecipient === null || $locked->legacy || $locked->database_status !== 'processing' || $locked->database_dispatch_token !== $this->token || ! $this->eligible($meeting, $lockedRecipient, (string) $locked->window)) return;
                $notification->id = $notificationId;
                Notification::sendNow($lockedRecipient, $notification, ['database']);
                $this->markSucceeded($dispatch->id);
            });
        } catch (Throwable $exception) {
            if ($attempt >= 3) {
                $this->markFailed($dispatch->id);
                $this->fail($exception);
                return;
            }

            throw $exception;
        }
    }

    public static function notificationId(string $meetingId, string $window, string $recipientId): string
    {
        $hash = hash('sha256', 'meeting_reminder:'.$meetingId.':'.$window.':'.$recipientId);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    private function eligible(?Meeting $meeting, ?User $recipient, string $window): bool
    {
        if ($meeting === null || $recipient === null || $recipient->status !== UserStatus::InProgress) {
            return false;
        }

        if ($meeting->status !== MeetingStatus::Reserved || ! in_array($window, ['eve', 'one_hour_before'], true)) {
            return false;
        }

        if ($recipient->id !== $meeting->student_id && $recipient->id !== $meeting->coach_id) {
            return false;
        }

        return CarbonImmutable::instance($meeting->scheduled_at)
            ->setTimezone('Asia/Tokyo')
            ->greaterThan(CarbonImmutable::now('Asia/Tokyo'));
    }

    private function samePayload(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function markSucceeded(string $dispatchId): void
    {
        MeetingReminderDispatch::query()->whereKey($dispatchId)->where('legacy', false)->where('database_status', 'processing')->where('database_dispatch_token', $this->token)->update([
            'database_status' => 'succeeded',
            'database_completed_at' => now(),
        ]);
        if ($this->queueAttemptUuid !== null) {
            MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $dispatchId)->where('channel', 'database')->where('dispatch_token', $this->token)->whereIn('state', ['preparing', 'enqueued'])->update(['state' => 'completed', 'completed_at' => now()]);
        }
    }

    private function markFailed(string $dispatchId): void
    {
        MeetingReminderDispatch::query()->whereKey($dispatchId)->where('legacy', false)->where('database_status', 'processing')->where('database_dispatch_token', $this->token)->update([
            'database_status' => 'failed',
            'database_completed_at' => now(),
        ]);
        if ($this->queueAttemptUuid !== null) {
            MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $dispatchId)->where('channel', 'database')->where('dispatch_token', $this->token)->whereIn('state', ['preparing', 'enqueued'])->update(['state' => 'failed', 'failed_at' => now()]);
        }
    }
}
