<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Meeting;

use App\Enums\MeetingStatus;
use App\Jobs\Meeting\MeetingReminderDatabaseNotificationJob;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\User;
use App\UseCases\MeetingReminder\AcquireQueueDispatchAction;
use App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

final class MeetingReminderDatabaseNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_notification_is_saved_and_mail_state_is_unchanged(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch();
        (new MeetingReminderDatabaseNotificationJob($dispatch->id, $token))->handle();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'id' => MeetingReminderDatabaseNotificationJob::notificationId($meeting->id, 'eve', $student->id),
        ]);
        $dispatch->refresh();
        $this->assertSame('succeeded', $dispatch->database_status);
        $this->assertSame('pending', $dispatch->mail_status);
    }

    public function test_preparing_attempt_can_execute_and_be_completed(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => $uuid, 'dispatch_id' => $dispatch->id, 'channel' => 'database', 'dispatch_token' => $token, 'state' => 'preparing']);
        (new MeetingReminderDatabaseNotificationJob($dispatch->id, $token, $uuid))->handle();
        $this->assertSame('completed', MeetingReminderQueueAttempt::where('queue_attempt_uuid', $uuid)->value('state'));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $student->id, 'id' => MeetingReminderDatabaseNotificationJob::notificationId($meeting->id, 'eve', $student->id)]);
    }

    public function test_same_job_is_idempotent_and_stale_token_is_ignored(): void
    {
        [, , $dispatch, $token] = $this->claimedDispatch();
        $job = new MeetingReminderDatabaseNotificationJob($dispatch->id, $token);
        $job->handle();
        $job->handle();
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $dispatch->recipient_id)->count());
        (new MeetingReminderDatabaseNotificationJob($dispatch->id, '00000000-0000-0000-0000-000000000000'))->handle();
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $dispatch->recipient_id)->count());
    }

    public function test_legacy_and_succeeded_dispatches_are_skipped(): void
    {
        [$meeting, $student] = $this->meeting();
        $legacy = MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'eve', 'recipient_id' => $student->id]);
        (new MeetingReminderDatabaseNotificationJob($legacy->id, 'legacy'))->handle();
        $succeeded = MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'one_hour_before', 'recipient_id' => $student->id, 'legacy' => false, 'database_status' => 'succeeded', 'mail_status' => 'pending', 'database_dispatch_token' => '11111111-1111-1111-1111-111111111111']);
        (new MeetingReminderDatabaseNotificationJob($succeeded->id, (string) $succeeded->database_dispatch_token))->handle();
        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_canceled_or_started_meeting_is_skipped(): void
    {
        [$meeting, , $dispatch, $token] = $this->claimedDispatch();
        $meeting->update(['status' => MeetingStatus::Canceled]);
        (new MeetingReminderDatabaseNotificationJob($dispatch->id, $token))->handle();
        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_notification_id_is_deterministic_per_event_and_recipient(): void
    {
        $first = MeetingReminderDatabaseNotificationJob::notificationId('m1', 'eve', 'u1');
        $this->assertSame($first, MeetingReminderDatabaseNotificationJob::notificationId('m1', 'eve', 'u1'));
        $this->assertNotSame($first, MeetingReminderDatabaseNotificationJob::notificationId('m2', 'eve', 'u1'));
        $this->assertNotSame($first, MeetingReminderDatabaseNotificationJob::notificationId('m1', 'one_hour_before', 'u1'));
    }

    private function claimedDispatch(): array
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        [$meeting, $student] = $this->meeting('2026-10-11 10:00:00');
        $dispatch = (new AcquireQueueDispatchAction)($meeting->id, 'eve', $student->id)->dispatch;
        $claim = (new ClaimMeetingReminderDispatchAction)($dispatch->id, 'database');
        return [$meeting, $student, $dispatch->refresh(), $claim->token];
    }

    private function meeting(string $scheduled = '2026-10-11 10:00:00'): array
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->create(['student_id' => $student->id, 'coach_id' => $coach->id, 'scheduled_at' => $scheduled, 'status' => MeetingStatus::Reserved]);
        return [$meeting, $student];
    }
}
