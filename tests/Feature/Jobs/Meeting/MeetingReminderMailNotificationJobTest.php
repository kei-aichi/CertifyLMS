<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Meeting;

use App\Enums\MeetingStatus;
use App\Jobs\Meeting\MeetingReminderMailNotificationJob;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\UseCases\MeetingReminder\AcquireQueueDispatchAction;
use App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MeetingReminderMailNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_notification_is_sent_and_database_state_is_unchanged(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch();
        Notification::fake();
        (new MeetingReminderMailNotificationJob($dispatch->id, $token))->handle();

        Notification::assertSentTo($student, MeetingReminderNotification::class);
        $dispatch->refresh();
        $this->assertSame('succeeded', $dispatch->mail_status);
        $this->assertSame('pending', $dispatch->database_status);
        $this->assertSame($meeting->id, $dispatch->meeting_id);
    }

    public function test_eve_mail_content_and_channel_match_existing_notification_specification(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch('eve');
        Notification::fake();
        (new MeetingReminderMailNotificationJob($dispatch->id, $token))->handle();

        Notification::assertSentTo($student, MeetingReminderNotification::class, function (MeetingReminderNotification $notification, array $channels) use ($meeting, $student): bool {
            $mail = $notification->toMail($student);
            $this->assertContains('mail', $channels);
            $this->assertSame('【面談リマインダー】明日の面談について', $mail->subject);
            $this->assertSame($student->name.'さん', $mail->greeting);
            $this->assertContains('明日の面談をお知らせします。', $mail->introLines);
            $this->assertContains('面談日時: 2026年10月11日 10:00', $mail->introLines);
            $this->assertSame('面談詳細を確認する', $mail->actionText);
            $this->assertSame(route('meetings.show', ['meeting' => $meeting->id]), $mail->actionUrl);
            return true;
        });
    }

    public function test_one_hour_before_mail_content_matches_existing_notification_specification(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch('one_hour_before', '2026-10-10 19:00:00');
        Notification::fake();
        (new MeetingReminderMailNotificationJob($dispatch->id, $token))->handle();

        Notification::assertSentTo($student, MeetingReminderNotification::class, function (MeetingReminderNotification $notification, array $channels) use ($meeting, $student): bool {
            $mail = $notification->toMail($student);
            $this->assertContains('mail', $channels);
            $this->assertSame('【面談リマインダー】面談開始1時間前のお知らせ', $mail->subject);
            $this->assertContains('面談開始1時間前のお知らせです。', $mail->introLines);
            $this->assertContains('面談日時: 2026年10月10日 19:00', $mail->introLines);
            $this->assertSame(route('meetings.show', ['meeting' => $meeting->id]), $mail->actionUrl);
            return true;
        });
    }

    public function test_three_failures_mark_failed_and_do_not_change_database_state(): void
    {
        [, , $dispatch, $token] = $this->claimedDispatch();
        Notification::shouldReceive('sendNow')->times(3)->andThrow(new \RuntimeException('smtp failure'));
        $job = new MeetingReminderMailNotificationJob($dispatch->id, $token);
        try { $job->handle(); } catch (\Throwable) {}
        try { $job->handle(); } catch (\Throwable) {}
        try { $job->handle(); } catch (\Throwable) {}
        $dispatch->refresh();
        $this->assertSame('failed', $dispatch->mail_status);
        $this->assertSame('pending', $dispatch->database_status);
        $this->assertSame(3, (int) \App\Models\NotificationDeliveryAttempt::query()->where('event_key', 'meeting_reminder:'.$dispatch->meeting_id.':'.$dispatch->window)->where('recipient_id', $dispatch->recipient_id)->where('channel', 'mail')->value('attempts'));
    }

    public function test_matching_attempt_uuid_in_preparing_state_sends_and_completes_ledger(): void
    {
        [$meeting, $student, $dispatch, $token] = $this->claimedDispatch();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => $uuid, 'dispatch_id' => $dispatch->id, 'channel' => 'mail', 'dispatch_token' => $token, 'state' => 'preparing']);
        Notification::fake();
        (new MeetingReminderMailNotificationJob($dispatch->id, $token, $uuid))->handle();
        Notification::assertSentTo($student, MeetingReminderNotification::class);
        $this->assertSame('completed', MeetingReminderQueueAttempt::where('queue_attempt_uuid', $uuid)->value('state'));
        $this->assertSame('pending', $dispatch->refresh()->database_status);
    }

    public function test_mismatched_attempt_uuid_does_not_send(): void
    {
        [, $student, $dispatch, $token] = $this->claimedDispatch();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => $uuid, 'dispatch_id' => $dispatch->id, 'channel' => 'mail', 'dispatch_token' => $token, 'state' => 'preparing']);
        Notification::fake();
        (new MeetingReminderMailNotificationJob($dispatch->id, $token, (string) \Illuminate\Support\Str::uuid()))->handle();
        Notification::assertNothingSent();
        $this->assertSame('preparing', MeetingReminderQueueAttempt::where('queue_attempt_uuid', $uuid)->value('state'));
        $this->assertSame('processing', $dispatch->refresh()->mail_status);
    }

    public function test_three_failures_mark_matching_attempt_failed(): void
    {
        [, , $dispatch, $token] = $this->claimedDispatch();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => $uuid, 'dispatch_id' => $dispatch->id, 'channel' => 'mail', 'dispatch_token' => $token, 'state' => 'enqueued']);
        Notification::shouldReceive('sendNow')->times(3)->andThrow(new \RuntimeException('smtp failure'));
        $job = new MeetingReminderMailNotificationJob($dispatch->id, $token, $uuid);
        try { $job->handle(); } catch (\Throwable) {}
        try { $job->handle(); } catch (\Throwable) {}
        try { $job->handle(); } catch (\Throwable) {}
        $this->assertSame('failed', MeetingReminderQueueAttempt::where('queue_attempt_uuid', $uuid)->value('state'));
        $this->assertSame('failed', $dispatch->refresh()->mail_status);
        $this->assertSame('pending', $dispatch->database_status);
    }

    public function test_stale_token_legacy_succeeded_and_canceled_dispatches_are_skipped(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        [$meeting, $student] = $this->meeting();
        $legacy = MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'eve', 'recipient_id' => $student->id]);
        $succeeded = MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'one_hour_before', 'recipient_id' => $student->id, 'legacy' => false, 'database_status' => 'pending', 'mail_status' => 'succeeded', 'mail_dispatch_token' => '11111111-1111-1111-1111-111111111111']);
        $queueMeeting = Meeting::factory()->create(['student_id' => $student->id, 'coach_id' => User::factory()->coach()->create()->id, 'scheduled_at' => '2026-10-11 10:00:00', 'status' => MeetingStatus::Reserved]);
        $queue = (new AcquireQueueDispatchAction)($queueMeeting->id, 'eve', $student->id)->dispatch;
        $claim = (new ClaimMeetingReminderDispatchAction)($queue->id, 'mail');
        Notification::fake();
        (new MeetingReminderMailNotificationJob($legacy->id, 'legacy'))->handle();
        (new MeetingReminderMailNotificationJob($succeeded->id, (string) $succeeded->mail_dispatch_token))->handle();
        (new MeetingReminderMailNotificationJob($queue->id, '00000000-0000-0000-0000-000000000000'))->handle();
        $queueMeeting->update(['status' => MeetingStatus::Canceled]);
        (new MeetingReminderMailNotificationJob($queue->id, $claim->token))->handle();
        Notification::assertNothingSent();
    }

    public function test_job_configuration_matches_retry_policy(): void
    {
        $job = new MeetingReminderMailNotificationJob('dispatch', 'token');
        $this->assertSame(0, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
    }

    private function claimedDispatch(string $window = 'eve', string $scheduled = '2026-10-11 10:00:00'): array
    {
        CarbonImmutable::setTestNow($window === 'eve' ? '2026-10-10 18:00:30' : '2026-10-10 18:00:30', 'Asia/Tokyo');
        [$meeting, $student] = $this->meeting($scheduled);
        $dispatch = (new AcquireQueueDispatchAction)($meeting->id, $window, $student->id)->dispatch;
        $claim = (new ClaimMeetingReminderDispatchAction)($dispatch->id, 'mail');
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
