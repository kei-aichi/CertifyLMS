<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class DispatchAnnouncementActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_recipients_are_recorded_as_succeeded_without_notifications(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('zero'));

        $this->assertSame('succeeded', $result->announcement->dispatch_status->value);
        $this->assertSame(0, $result->announcement->dispatched_count);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_delivery_saves_processing_before_sending_and_finishes_succeeded(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('success'));

        $this->assertSame('succeeded', $result->announcement->dispatch_status->value);
        $this->assertNotNull($result->announcement->dispatched_at);
        $this->assertSame(1, $result->announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'type' => AnnouncementNotification::class,
        ]);
    }

    public function test_existing_failed_announcement_is_not_dispatched_again(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $announcement = Announcement::factory()->failed()->create([
            'created_by' => $admin->id,
            'submission_key' => 'existing-failed',
        ]);

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('existing-failed'));

        $this->assertTrue($result->alreadyExists);
        $this->assertSame($announcement->id, $result->announcement->id);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_existing_processing_and_succeeded_announcements_are_not_dispatched_again(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create();

        foreach (['processing', 'succeeded'] as $status) {
            $key = 'existing-'.$status;
            $announcement = Announcement::factory()->create([
                'created_by' => $admin->id,
                'submission_key' => $key,
                'dispatch_status' => $status,
            ]);

            $result = app(DispatchAnnouncementAction::class)($admin, $this->payload($key));

            $this->assertTrue($result->alreadyExists);
            $this->assertSame($announcement->id, $result->announcement->id);
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_certification_target_dispatches_only_learning_enrolled_students(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $learningStudent = User::factory()->student()->create();
        $passedStudent = User::factory()->student()->create();
        Enrollment::factory()->for($learningStudent)->for($certification)->learning()->create();
        Enrollment::factory()->for($passedStudent)->for($certification)->passed()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, [
            ...$this->payload('certification'),
            'target_type' => 'certification',
            'target_certification_id' => $certification->id,
        ]);

        $this->assertSame(1, $result->announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $learningStudent->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $passedStudent->id]);
    }

    public function test_user_target_dispatches_only_the_requested_active_student(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, [
            ...$this->payload('user'),
            'target_type' => 'user',
            'target_user_id' => $student->id,
        ]);

        $this->assertSame(1, $result->announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $student->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $otherStudent->id]);
    }

    public function test_delivery_exception_keeps_history_and_marks_it_failed(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create();
        $this->app['events']->listen(NotificationSending::class, static function (): void {
            throw new RuntimeException('notification failure');
        });

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('failed'));

        $this->assertSame('failed', $result->announcement->fresh()->dispatch_status->value);
        $this->assertDatabaseHas('announcements', [
            'id' => $result->announcement->id,
            'dispatch_status' => 'failed',
        ]);
    }

    public function test_mail_failure_after_database_notification_keeps_history_and_marks_failed(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $this->app['events']->listen(NotificationSending::class, static function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                throw new RuntimeException('mail failure');
            }
        });

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('mail-failed'));

        $this->assertSame('failed', $result->announcement->dispatch_status->value);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_failed_status_update_failure_leaves_processing_and_logs_separately(): void
    {
        Mail::fake();
        Log::spy();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create();
        $this->app['events']->listen(NotificationSending::class, static function (): void {
            throw new RuntimeException('notification failure');
        });
        $this->app['events']->listen('eloquent.saving: '.Announcement::class, static function (Announcement $announcement): void {
            if ($announcement->dispatch_status?->value === 'failed') {
                throw new RuntimeException('status update failure');
            }
        });

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('status-failed'));

        $this->assertDatabaseHas('announcements', [
            'id' => $result->announcement->id,
            'dispatch_status' => 'processing',
        ]);
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_database_notification_is_not_sent_before_announcement_transaction_commits(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create();
        $notificationCountAtCreate = null;
        $this->app['events']->listen('eloquent.created: '.Announcement::class, static function () use (&$notificationCountAtCreate): void {
            $notificationCountAtCreate = DatabaseNotification::query()->count();
        });

        app(DispatchAnnouncementAction::class)($admin, $this->payload('after-commit'));

        $this->assertSame(0, $notificationCountAtCreate);
    }

    public function test_chunked_delivery_notifies_all_101_recipients_once(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->count(101)->create();

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('chunked'));

        $this->assertSame(101, $result->announcement->dispatched_count);
        $this->assertSame(101, DatabaseNotification::query()->count());
        $this->assertSame(101, DatabaseNotification::query()
            ->where('type', AnnouncementNotification::class)
            ->count());
    }

    /** @return array<string, string> */
    private function payload(string $key): array
    {
        return [
            'target_type' => 'all',
            'title' => 'テストお知らせ',
            'body' => 'テスト本文',
            'submission_key' => $key,
        ];
    }
}
