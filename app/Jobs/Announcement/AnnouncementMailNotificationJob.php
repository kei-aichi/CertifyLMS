<?php

declare(strict_types=1);

namespace App\Jobs\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationDeliveryAttempt;
use App\Notifications\AnnouncementNotification;
use App\Services\Notifications\NotificationAttemptTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class AnnouncementMailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public function __construct(
        public readonly string $recipientRowId,
        public readonly string $dispatchToken,
    ) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('announcement-mail:'.$this->recipientRowId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $row = AnnouncementRecipient::query()->find($this->recipientRowId);
        $user = $row?->recipient()->first();

        if ($row === null || $user === null || $row->mail_status === 'succeeded') {
            return;
        }

        $row->forceFill(['mail_processing_started_at' => now()])->save();

        $previousAttempts = (int) NotificationDeliveryAttempt::query()
            ->where('event_key', 'announcement:'.$row->announcement_id)
            ->where('recipient_id', $row->recipient_id)
            ->where('channel', 'mail')
            ->value('attempts');
        if ($previousAttempts >= 3) {
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        $attempt = app(NotificationAttemptTracker::class)
            ->increment('announcement:'.$row->announcement_id, $row->recipient_id, 'mail');

        if ($attempt > 3) {
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        try {
            Notification::sendNow($user, new AnnouncementNotification($this->snapshotAnnouncement($row)), ['mail']);
            $row->forceFill([
                'mail_status' => 'succeeded',
                'mail_completed_at' => now(),
                'mail_processing_started_at' => null,
            ])->save();
            AggregateAnnouncementStatusJob::dispatch($row->announcement_id);
        } catch (Throwable $exception) {
            if ($attempt >= 3) {
                $this->fail($exception);
                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        AnnouncementRecipient::query()
            ->whereKey($this->recipientRowId)
            ->where('mail_status', '!=', 'succeeded')
            ->update([
                'mail_status' => 'failed',
                'mail_completed_at' => now(),
                'mail_processing_started_at' => null,
            ]);
        AggregateAnnouncementStatusJob::dispatch(
            AnnouncementRecipient::query()->whereKey($this->recipientRowId)->value('announcement_id')
        );
    }

    private function snapshotAnnouncement(AnnouncementRecipient $row): Announcement
    {
        $announcement = new Announcement();
        $announcement->forceFill([
            'id' => $row->announcement_id,
            'title' => $row->title_snapshot,
            'body' => $row->body_snapshot,
        ]);

        return $announcement;
    }
}
