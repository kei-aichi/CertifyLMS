<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Announcement;

use App\Enums\UserStatus;
use App\Jobs\Announcement\AnnouncementDatabaseNotificationJob;
use App\Jobs\Announcement\AnnouncementMailNotificationJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

final class AnnouncementNotificationJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_notification_is_created_once_with_fixed_uuid(): void
    {
        [$row, $user] = $this->recipient();
        $job = new AnnouncementDatabaseNotificationJob($row->id, 'database-token');

        $job->handle();
        $job->handle();

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $user->id)->count());
        $this->assertSame(
            AnnouncementDatabaseNotificationJob::notificationId($row->announcement_id, $user->id),
            DatabaseNotification::query()->firstOrFail()->id,
        );
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'database',
            'attempts' => 1,
        ]);
    }

    public function test_mail_job_is_independent_from_database_job(): void
    {
        [$row, $user] = $this->recipient();
        Notification::fake();

        (new AnnouncementMailNotificationJob($row->id, 'mail-token'))->handle();

        Notification::assertSentTo($user, AnnouncementNotification::class);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'mail',
            'attempts' => 1,
        ]);
    }

    public function test_graduated_and_withdrawn_recipients_are_not_filtered_at_job_time(): void
    {
        foreach ([UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            [$row, $user] = $this->recipient();
            $user->update(['status' => $status]);

            (new AnnouncementDatabaseNotificationJob($row->id, 'database-token'))->handle();

            $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
        }
    }

    public function test_database_job_does_not_send_a_fourth_time(): void
    {
        [$row, $user] = $this->recipient();
        NotificationDeliveryAttempt::create([
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'database',
            'attempts' => 3,
        ]);

        (new AnnouncementDatabaseNotificationJob($row->id, 'database-token'))->handle();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'database',
            'attempts' => 3,
        ]);
    }

    public function test_processing_started_at_is_set_during_a_failed_send_and_cleared_on_success(): void
    {
        [$row] = $this->recipient();

        (new AnnouncementDatabaseNotificationJob($row->id, 'database-token'))->handle();

        $this->assertNull($row->fresh()->database_processing_started_at);

        [$failedRow] = $this->recipient();
        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('temporary failure'));

        try {
            (new AnnouncementDatabaseNotificationJob($failedRow->id, 'database-token'))->handle();
        } catch (RuntimeException) {
            // The queue worker will rethrow this for its normal retry path.
        }

        $this->assertNotNull($failedRow->fresh()->database_processing_started_at);
    }

    public function test_missing_database_recipient_retries_three_times_and_is_failed(): void
    {
        [$row, $user] = $this->recipient();
        $user->delete();

        $job = new AnnouncementDatabaseNotificationJob($row->id, 'database-token');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt < 3) {
                try {
                    $job->handle();
                } catch (RuntimeException) {
                    // The worker retries the thrown exception.
                }
            } else {
                $job->handle();
            }
        }

        $this->assertDatabaseHas('announcement_recipients', [
            'id' => $row->id,
            'database_status' => 'failed',
        ]);
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'database',
            'attempts' => 3,
        ]);
    }

    public function test_missing_mail_recipient_retries_three_times_and_does_not_change_database_status(): void
    {
        [$row, $user] = $this->recipient();
        $user->delete();

        $job = new AnnouncementMailNotificationJob($row->id, 'mail-token');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt < 3) {
                try {
                    $job->handle();
                } catch (RuntimeException) {
                    // The worker retries the thrown exception.
                }
            } else {
                $job->handle();
            }
        }

        $this->assertDatabaseHas('announcement_recipients', [
            'id' => $row->id,
            'mail_status' => 'failed',
            'database_status' => 'pending',
        ]);
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'event_key' => 'announcement:'.$row->announcement_id,
            'recipient_id' => $user->id,
            'channel' => 'mail',
            'attempts' => 3,
        ]);
    }

    /** @return array{AnnouncementRecipient, User} */
    private function recipient(): array
    {
        $announcement = Announcement::factory()->create([
            'title' => 'Snapshot title',
            'body' => 'Snapshot body',
        ]);
        $user = User::factory()->student()->create();
        $row = AnnouncementRecipient::create([
            'announcement_id' => $announcement->id,
            'recipient_id' => $user->id,
            'title_snapshot' => 'Snapshot title',
            'body_snapshot' => 'Snapshot body',
        ]);

        return [$row, $user];
    }
}
