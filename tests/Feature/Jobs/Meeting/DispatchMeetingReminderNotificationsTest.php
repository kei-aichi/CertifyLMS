<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Meeting;

use App\Enums\MeetingStatus;
use App\Jobs\Meeting\DispatchMeetingReminderNotifications;
use App\Jobs\Meeting\MeetingReminderDatabaseNotificationJob;
use App\Jobs\Meeting\MeetingReminderMailNotificationJob;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DispatchMeetingReminderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_database_and_mail_channels_are_queued_and_timestamped(): void
    {
        [$meeting, $student] = $this->meeting();
        $dispatch = $this->queueDispatch($meeting, $student);
        Queue::fake();

        (new DispatchMeetingReminderNotifications($dispatch->id))->handle(app(\App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction::class));

        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, 1);
        Queue::assertPushed(MeetingReminderMailNotificationJob::class, 1);
        $this->assertSame(2, MeetingReminderQueueAttempt::where('dispatch_id', $dispatch->id)->count());
        $this->assertSame(2, MeetingReminderQueueAttempt::where('dispatch_id', $dispatch->id)->where('state', 'enqueued')->count());
        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, fn ($job) => MeetingReminderQueueAttempt::where('queue_attempt_uuid', $job->queueAttemptUuid)->where('channel', 'database')->exists());
        Queue::assertPushed(MeetingReminderMailNotificationJob::class, fn ($job) => MeetingReminderQueueAttempt::where('queue_attempt_uuid', $job->queueAttemptUuid)->where('channel', 'mail')->exists());
        $dispatch->refresh();
        $this->assertSame('processing', $dispatch->database_status);
        $this->assertSame('processing', $dispatch->mail_status);
        $this->assertNotNull($dispatch->database_queued_at);
        $this->assertNotNull($dispatch->mail_queued_at);
    }

    public function test_only_pending_channel_is_queued(): void
    {
        [$meeting, $student] = $this->meeting();
        $dispatch = $this->queueDispatch($meeting, $student);
        $dispatch->update(['mail_status' => 'succeeded']);
        Queue::fake();

        (new DispatchMeetingReminderNotifications($dispatch->id))->handle(app(\App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction::class));

        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, 1);
        Queue::assertNotPushed(MeetingReminderMailNotificationJob::class);
        $this->assertNotNull($dispatch->refresh()->database_queued_at);
        $this->assertNull($dispatch->mail_queued_at);
    }

    public function test_legacy_processing_succeeded_and_failed_dispatches_are_not_requeued(): void
    {
        [$meeting, $student] = $this->meeting();
        $dispatch = $this->queueDispatch($meeting, $student);
        $dispatch->update(['legacy' => true]);
        Queue::fake();
        (new DispatchMeetingReminderNotifications($dispatch->id))->handle(app(\App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction::class));
        Queue::assertNothingPushed();
    }

    public function test_repeated_dispatch_does_not_duplicate_after_claim(): void
    {
        [$meeting, $student] = $this->meeting();
        $dispatch = $this->queueDispatch($meeting, $student);
        Queue::fake();
        $job = new DispatchMeetingReminderNotifications($dispatch->id);
        $claim = app(\App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction::class);
        $job->handle($claim);
        $job->handle($claim);
        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, 1);
        Queue::assertPushed(MeetingReminderMailNotificationJob::class, 1);
    }

    public function test_canceled_meeting_is_not_queued(): void
    {
        [$meeting, $student] = $this->meeting();
        $dispatch = $this->queueDispatch($meeting, $student);
        $meeting->update(['status' => MeetingStatus::Canceled]);
        Queue::fake();
        (new DispatchMeetingReminderNotifications($dispatch->id))->handle(app(\App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction::class));
        Queue::assertNothingPushed();
    }

    private function queueDispatch(Meeting $meeting, User $student): MeetingReminderDispatch
    {
        return MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'eve', 'recipient_id' => $student->id, 'legacy' => false, 'database_status' => 'pending', 'mail_status' => 'pending']);
    }

    private function meeting(): array
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->create(['student_id' => $student->id, 'coach_id' => $coach->id, 'scheduled_at' => '2026-10-11 10:00:00', 'status' => MeetingStatus::Reserved]);
        return [$meeting, $student];
    }
}
