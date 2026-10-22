<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Jobs\Announcement\DispatchAnnouncementRecipientsJob;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DispatchAnnouncementActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_zero_recipients_are_recorded_as_succeeded_without_notifications(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('zero'));

        $this->assertSame('succeeded', $result->announcement->dispatch_status->value);
        $this->assertSame(0, $result->announcement->dispatched_count);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_delivery_saves_processing_and_dispatches_after_commit(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('success'));

        $this->assertSame('processing', $result->announcement->dispatch_status->value);
        $this->assertNotNull($result->announcement->dispatched_at);
        $this->assertSame(1, $result->announcement->dispatched_count);
        $this->assertDatabaseHas('announcement_recipients', [
            'announcement_id' => $result->announcement->id,
            'recipient_id' => $student->id,
            'title_snapshot' => 'テストお知らせ',
            'body_snapshot' => 'テスト本文',
            'database_status' => 'pending',
            'mail_status' => 'pending',
        ]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertPushed(DispatchAnnouncementRecipientsJob::class, 1);
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
        $this->assertDatabaseCount('announcement_recipients', 1);
        $this->assertDatabaseHas('announcement_recipients', ['recipient_id' => $learningStudent->id]);
        $this->assertDatabaseMissing('announcement_recipients', ['recipient_id' => $passedStudent->id]);
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
        $this->assertDatabaseHas('announcement_recipients', ['recipient_id' => $student->id]);
        $this->assertDatabaseMissing('announcement_recipients', ['recipient_id' => $otherStudent->id]);
    }

    public function test_new_delivery_remains_processing_until_queue_jobs_finish(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create();
        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('failed'));

        $this->assertSame('processing', $result->announcement->fresh()->dispatch_status->value);
        $this->assertDatabaseHas('announcements', [
            'id' => $result->announcement->id,
            'dispatch_status' => 'processing',
        ]);
    }

    public function test_new_delivery_does_not_send_mail_in_http_request(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $result = app(DispatchAnnouncementAction::class)($admin, $this->payload('mail-failed'));

        $this->assertSame('processing', $result->announcement->dispatch_status->value);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_rollback_does_not_enqueue_dispatcher(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            app(DispatchAnnouncementAction::class)($admin, [
                ...$this->payload('rollback'),
                'title' => null,
            ]);
        } finally {
            Queue::assertNothingPushed();
        }
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
        $this->assertSame(101, AnnouncementRecipient::query()
            ->where('announcement_id', $result->announcement->id)
            ->count());
        $this->assertSame(0, DatabaseNotification::query()->count());
        Queue::assertPushed(DispatchAnnouncementRecipientsJob::class, 1);
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
