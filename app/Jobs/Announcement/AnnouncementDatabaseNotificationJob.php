<?php

declare(strict_types=1);

namespace App\Jobs\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
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

final class AnnouncementDatabaseNotificationJob implements ShouldQueue
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
            (new WithoutOverlapping('announcement-db:'.$this->recipientRowId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $row = AnnouncementRecipient::query()->find($this->recipientRowId);
        $user = $row?->recipient()->first();

        if ($row === null || $user === null || $row->database_status === 'succeeded') {
            return;
        }

        $row->forceFill(['database_processing_started_at' => now()])->save();

        $announcement = $this->snapshotAnnouncement($row);
        $notificationId = self::notificationId($row->announcement_id, $row->recipient_id);
        $notification = new AnnouncementNotification($announcement);

        // Existing notifications must be checked before incrementing attempts.
        $existing = $user->notifications()->whereKey($notificationId)->first();
        if ($existing !== null) {
            $expected = $notification->toArray($user);
            if ($existing->data === $expected) {
                $this->markSucceeded($row);
                return;
            }

            throw new RuntimeException('Announcement notification UUID has conflicting data.');
        }

        $previousAttempts = (int) NotificationDeliveryAttempt::query()
            ->where('event_key', 'announcement:'.$row->announcement_id)
            ->where('recipient_id', $row->recipient_id)
            ->where('channel', 'database')
            ->value('attempts');
        if ($previousAttempts >= 3) {
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        $attempt = app(NotificationAttemptTracker::class)
            ->increment('announcement:'.$row->announcement_id, $row->recipient_id, 'database');

        if ($attempt > 3) {
            $this->fail(new RuntimeException('notification delivery attempts exceeded'));
            return;
        }

        $notification->id = $notificationId;

        try {
            Notification::sendNow($user, $notification, ['database']);
            $this->markSucceeded($row);
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
            ->where('database_status', '!=', 'succeeded')
            ->update([
                'database_status' => 'failed',
                'database_completed_at' => now(),
                'database_processing_started_at' => null,
            ]);
        AggregateAnnouncementStatusJob::dispatch(
            AnnouncementRecipient::query()->whereKey($this->recipientRowId)->value('announcement_id')
        );
    }

    public static function notificationId(string $announcementId, string $recipientId): string
    {
        $hash = hash('sha256', 'announcement:'.$announcementId.':'.$recipientId);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
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

    private function markSucceeded(AnnouncementRecipient $row): void
    {
        $row->forceFill([
            'database_status' => 'succeeded',
            'database_completed_at' => now(),
            'database_processing_started_at' => null,
        ])->save();
        AggregateAnnouncementStatusJob::dispatch($row->announcement_id);
    }
}
