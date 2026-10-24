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

final class MeetingReminderMailNotificationJob implements ShouldQueue
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
            (new WithoutOverlapping('meeting-reminder-mail:'.$this->dispatchId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $reference = MeetingReminderDispatch::query()->find($this->dispatchId);
        if ($reference === null) return;
        if ($this->queueAttemptUuid !== null && ! MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $this->dispatchId)->where('channel', 'mail')->where('dispatch_token', $this->token)->exists()) return;

        $context = DB::transaction(function () use ($reference): ?array {
            $meeting = Meeting::query()->with(['coach', 'student'])->lockForUpdate()->find($reference->meeting_id);
            $dispatch = MeetingReminderDispatch::query()->lockForUpdate()->find($this->dispatchId);
            $recipient = $dispatch ? User::query()->lockForUpdate()->find($dispatch->recipient_id) : null;
            if ($dispatch === null || $dispatch->meeting_id !== $meeting?->id || $dispatch->isLegacy()
                || $dispatch->mail_status !== 'processing'
                || $dispatch->mail_dispatch_token !== $this->token
                || ! $this->eligible($meeting, $recipient, (string) $dispatch->window)) return null;

            return [$dispatch, $meeting, $recipient];
        });
        if ($context === null) return;

        [$dispatch, $meeting, $recipient] = $context;
        $attempt = app(NotificationAttemptTracker::class)->increment(
            'meeting_reminder:'.$dispatch->meeting_id.':'.$dispatch->window,
            $dispatch->recipient_id,
            'mail',
            $this->cycle,
        );
        if ($attempt > 3) {
            $this->markFailed($dispatch->id);
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        try {
            Notification::sendNow($recipient, new MeetingReminderNotification($meeting, $dispatch->window), ['mail']);
            $this->markSucceeded($dispatch->id);
        } catch (Throwable $exception) {
            if ($attempt >= 3) {
                $this->markFailed($dispatch->id);
                $this->fail($exception);
                return;
            }

            throw $exception;
        }
    }

    private function eligible(?Meeting $meeting, ?User $recipient, string $window): bool
    {
        if ($meeting === null || $recipient === null || $recipient->status !== UserStatus::InProgress) return false;
        if ($meeting->status !== MeetingStatus::Reserved || ! in_array($window, ['eve', 'one_hour_before'], true)) return false;
        if ($recipient->id !== $meeting->student_id && $recipient->id !== $meeting->coach_id) return false;

        return CarbonImmutable::instance($meeting->scheduled_at)->setTimezone('Asia/Tokyo')->greaterThan(CarbonImmutable::now('Asia/Tokyo'));
    }

    private function markSucceeded(string $dispatchId): void
    {
        MeetingReminderDispatch::query()->whereKey($dispatchId)->where('legacy', false)->where('mail_status', 'processing')->where('mail_dispatch_token', $this->token)->update(['mail_status' => 'succeeded', 'mail_completed_at' => now()]);
        if ($this->queueAttemptUuid !== null) MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $dispatchId)->where('channel', 'mail')->where('dispatch_token', $this->token)->whereIn('state', ['preparing', 'enqueued'])->update(['state' => 'completed', 'completed_at' => now()]);
    }

    private function markFailed(string $dispatchId): void
    {
        MeetingReminderDispatch::query()->whereKey($dispatchId)->where('legacy', false)->where('mail_status', 'processing')->where('mail_dispatch_token', $this->token)->update(['mail_status' => 'failed', 'mail_completed_at' => now()]);
        if ($this->queueAttemptUuid !== null) MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $this->queueAttemptUuid)->where('dispatch_id', $dispatchId)->where('channel', 'mail')->where('dispatch_token', $this->token)->whereIn('state', ['preparing', 'enqueued'])->update(['state' => 'failed', 'failed_at' => now()]);
    }
}
