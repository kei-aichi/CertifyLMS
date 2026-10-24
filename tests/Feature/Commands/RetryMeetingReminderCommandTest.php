<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Jobs\Meeting\MeetingReminderDatabaseNotificationJob;
use App\Jobs\Meeting\MeetingReminderMailNotificationJob;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
use App\Services\Notifications\NotificationAttemptTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class RetryMeetingReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_failed_job_is_requeued_for_the_requested_channel_only(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])->assertExitCode(0);

        Queue::assertPushed(MeetingReminderMailNotificationJob::class, fn ($job): bool => $job->dispatchId === $dispatch->id && $job->cycle === 1);
        Queue::assertNotPushed(MeetingReminderDatabaseNotificationJob::class);
        $this->assertSame('processing', $dispatch->refresh()->mail_status);
        $this->assertSame(2, MeetingReminderQueueAttempt::where('dispatch_id', $dispatch->id)->count());
    }

    public function test_database_channel_can_be_recovered_independently(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'database', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(0);
        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, 1);
        Queue::assertNotPushed(MeetingReminderMailNotificationJob::class);
        $this->assertSame('processing', $dispatch->refresh()->database_status);
        $this->assertSame('succeeded', $dispatch->mail_status);
    }

    public function test_invalid_uuid_or_metadata_is_rejected_without_queueing(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertNothingPushed();
    }

    public function test_cycle_history_is_preserved_and_new_cycle_is_independent(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        NotificationDeliveryAttempt::create(['event_key' => 'meeting_reminder:'.$dispatch->meeting_id.':eve', 'recipient_id' => $dispatch->recipient_id, 'channel' => 'database', 'cycle' => 0, 'attempts' => 3]);
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'database', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(0);
        Queue::assertPushed(MeetingReminderDatabaseNotificationJob::class, fn ($job): bool => $job->cycle === 1);
        $this->assertDatabaseHas('notification_delivery_attempts', ['cycle' => 0, 'attempts' => 3]);
    }

    public function test_second_retry_of_same_failed_job_is_rejected(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);
        $args = ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid];

        $this->artisan('meeting-reminders:retry', $args)->assertExitCode(0);
        $this->artisan('meeting-reminders:retry', $args)->assertExitCode(1);
        Queue::assertPushed(MeetingReminderMailNotificationJob::class, 1);
    }

    public function test_original_failed_uuid_is_rejected_after_a_newer_attempt_failed(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])->assertExitCode(0);
        $newAttempt = MeetingReminderQueueAttempt::query()->where('dispatch_id', $dispatch->id)->where('id', '!=', $attempt->id)->firstOrFail();
        // Database timestamp precision may collapse attempts created in one second.
        $newAttempt->update(['state' => 'failed', 'created_at' => $attempt->created_at]);
        $dispatch->refresh()->update(['mail_status' => 'failed', 'mail_dispatch_token' => $token]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertPushed(MeetingReminderMailNotificationJob::class, 1);
    }

    public function test_mail_recovery_warns_about_unknown_delivery_result(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])
            ->expectsOutputToContain('Mail delivery may have succeeded')
            ->assertExitCode(0);
    }

    public function test_recipient_must_be_in_progress_and_have_the_meeting_role(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'database', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        User::query()->whereKey($dispatch->recipient_id)->update(['status' => 'graduated']);
        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertNothingPushed();

        User::query()->whereKey($dispatch->recipient_id)->update(['status' => 'in_progress', 'role' => 'coach']);
        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertNothingPushed();
    }

    public function test_enqueue_failure_does_not_leave_dispatch_processing(): void
    {
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'database', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);

        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        app()->instance(BusDispatcher::class, $bus);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(1);
        $this->assertSame('failed', $dispatch->refresh()->database_status);
        $this->assertSame('unknown', MeetingReminderQueueAttempt::query()->where('dispatch_id', $dispatch->id)->where('id', '!=', $attempt->id)->value('state'));
    }

    public function test_canceled_or_started_meeting_is_rejected(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);
        Meeting::query()->whereKey($dispatch->meeting_id)->update(['status' => MeetingStatus::Canceled->value]);
        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertNothingPushed();
    }

    public function test_started_meeting_is_rejected(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('mail');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'mail', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);
        Meeting::query()->whereKey($dispatch->meeting_id)->update(['scheduled_at' => now()->subMinute()]);

        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'mail', 'failed_uuid' => $uuid])->assertExitCode(1);
        Queue::assertNothingPushed();
    }

    public function test_old_token_job_cannot_send_after_recovery_changes_token(): void
    {
        Queue::fake();
        [$dispatch, $attempt, $token] = $this->failedDispatch('database');
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload($dispatch, 'database', $token, $attempt->queue_attempt_uuid), 'exception' => 'failure', 'failed_at' => now()]);
        $this->artisan('meeting-reminders:retry', ['dispatch_id' => $dispatch->id, 'channel' => 'database', 'failed_uuid' => $uuid])->assertExitCode(0);

        (new MeetingReminderDatabaseNotificationJob($dispatch->id, $token, $attempt->queue_attempt_uuid))->handle();
        $this->assertSame('processing', $dispatch->refresh()->database_status);
    }

    public function test_new_cycle_is_limited_to_three_attempts(): void
    {
        $dispatch = $this->failedDispatch('mail')[0];
        $tracker = app(NotificationAttemptTracker::class);
        $key = 'meeting_reminder:'.$dispatch->meeting_id.':'.$dispatch->window;

        $this->assertSame(1, $tracker->increment($key, $dispatch->recipient_id, 'mail', 1));
        $this->assertSame(2, $tracker->increment($key, $dispatch->recipient_id, 'mail', 1));
        $this->assertSame(3, $tracker->increment($key, $dispatch->recipient_id, 'mail', 1));
        $this->assertSame(3, $tracker->increment($key, $dispatch->recipient_id, 'mail', 1));
    }

    public function test_payload_contains_structured_metadata_without_parsing_serialized_command(): void
    {
        $job = new MeetingReminderMailNotificationJob('dispatch-id', 'token', 'attempt-id');
        $queue = app('queue')->connection('database');
        $method = new ReflectionMethod(DatabaseQueue::class, 'createPayload');
        $method->setAccessible(true);
        $decoded = json_decode($method->invoke($queue, $job, 'default'), true);

        $this->assertSame(['dispatch_id' => 'dispatch-id', 'channel' => 'mail', 'dispatch_token' => 'token', 'queue_attempt_uuid' => 'attempt-id'], $decoded['meeting_reminder']);
        $this->assertSame(MeetingReminderMailNotificationJob::class, $decoded['data']['commandName']);
    }

    /** @return array{0:MeetingReminderDispatch,1:MeetingReminderQueueAttempt,2:string} */
    private function failedDispatch(string $channel): array
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forStudent($student)->forCoach($coach)->create(['scheduled_at' => now()->addHour()->addDay()]);
        $token = (string) Str::uuid();
        $dispatch = MeetingReminderDispatch::create(['meeting_id' => $meeting->id, 'window' => 'eve', 'recipient_id' => $student->id, 'legacy' => false, 'database_status' => $channel === 'database' ? 'failed' : 'succeeded', 'mail_status' => $channel === 'mail' ? 'failed' : 'succeeded', $channel.'_dispatch_token' => $token]);
        $attempt = MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => (string) Str::uuid(), 'dispatch_id' => $dispatch->id, 'channel' => $channel, 'dispatch_token' => $token, 'state' => 'failed']);
        return [$dispatch, $attempt, $token];
    }

    private function payload(MeetingReminderDispatch $dispatch, string $channel, string $token, string $attempt): string
    {
        return json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $channel === 'mail' ? MeetingReminderMailNotificationJob::class : MeetingReminderDatabaseNotificationJob::class, 'data' => ['commandName' => $channel === 'mail' ? MeetingReminderMailNotificationJob::class : MeetingReminderDatabaseNotificationJob::class], 'meeting_reminder' => ['dispatch_id' => $dispatch->id, 'channel' => $channel, 'dispatch_token' => $token, 'queue_attempt_uuid' => $attempt]], JSON_THROW_ON_ERROR);
    }
}
