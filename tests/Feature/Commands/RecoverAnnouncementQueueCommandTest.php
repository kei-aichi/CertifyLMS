<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\Announcement\AggregateAnnouncementStatusJob;
use App\Jobs\Announcement\AnnouncementDatabaseNotificationJob;
use App\Jobs\Announcement\AnnouncementMailNotificationJob;
use App\Jobs\Announcement\DispatchAnnouncementRecipientsJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecoverAnnouncementQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_announcement_dispatcher_is_requeued(): void
    {
        Queue::fake();
        [$announcement] = $this->recipient();

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertPushed(DispatchAnnouncementRecipientsJob::class, fn ($job): bool => $job->announcementId === $announcement->id);
        Queue::assertPushed(AggregateAnnouncementStatusJob::class);
    }

    public function test_stale_claim_without_queue_job_reuses_token(): void
    {
        Queue::fake();
        [$announcement, $recipient] = $this->recipient();
        $token = (string) Str::uuid();
        $recipient->update([
            'database_dispatch_token' => $token,
            'database_claimed_at' => now()->subSeconds(300),
        ]);

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertPushed(AnnouncementDatabaseNotificationJob::class, fn ($job): bool =>
            $job->recipientRowId === $recipient->id && $job->dispatchToken === $token
        );
        $this->assertNotNull($recipient->fresh()->database_queued_at);
    }

    public function test_existing_job_payload_prevents_duplicate_requeue(): void
    {
        Queue::fake();
        [, $recipient] = $this->recipient();
        $token = (string) Str::uuid();
        $recipient->update([
            'database_dispatch_token' => $token,
            'database_queued_at' => now()->subSeconds(300),
        ]);
        $this->insertQueuePayload($this->payloadForDatabaseJob($recipient->id, $token));

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertNotPushed(AnnouncementDatabaseNotificationJob::class);
    }

    public function test_different_recipient_or_token_does_not_match_existing_job(): void
    {
        Queue::fake();
        [, $recipient] = $this->recipient();
        $token = (string) Str::uuid();
        $recipient->update([
            'database_dispatch_token' => $token,
            'database_queued_at' => now()->subSeconds(300),
        ]);
        $this->insertQueuePayload($this->payloadForDatabaseJob((string) Str::ulid(), $token.'-suffix'));

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertPushed(AnnouncementDatabaseNotificationJob::class);
    }

    public function test_different_job_class_does_not_match_channel(): void
    {
        Queue::fake();
        [, $recipient] = $this->recipient();
        $token = (string) Str::uuid();
        $recipient->update([
            'database_dispatch_token' => $token,
            'database_queued_at' => now()->subSeconds(300),
        ]);
        $this->insertQueuePayload($this->payloadForMailJob($recipient->id, $token));

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertPushed(AnnouncementDatabaseNotificationJob::class);
    }

    public function test_ambiguous_payload_does_not_trigger_automatic_requeue(): void
    {
        Queue::fake();
        [, $recipient] = $this->recipient();
        $recipient->update([
            'database_dispatch_token' => (string) Str::uuid(),
            'database_queued_at' => now()->subSeconds(300),
        ]);
        $this->insertQueuePayload('{not-json');

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertNotPushed(AnnouncementDatabaseNotificationJob::class);
    }

    public function test_attempt_limit_prevents_fourth_delivery(): void
    {
        Queue::fake();
        [$announcement, $recipient] = $this->recipient();
        $recipient->update([
            'database_dispatch_token' => (string) Str::uuid(),
            'database_queued_at' => now()->subSeconds(300),
        ]);
        NotificationDeliveryAttempt::create([
            'event_key' => 'announcement:'.$announcement->id,
            'recipient_id' => $recipient->recipient_id,
            'channel' => 'database',
            'attempts' => 3,
        ]);

        $this->artisan('announcements')->assertExitCode(0);

        Queue::assertNotPushed(AnnouncementDatabaseNotificationJob::class);
    }

    /** @return array{Announcement, AnnouncementRecipient} */
    private function recipient(): array
    {
        $announcement = Announcement::factory()->create(['dispatch_status' => 'processing']);
        $user = User::factory()->student()->create();
        $recipient = AnnouncementRecipient::create([
            'announcement_id' => $announcement->id,
            'recipient_id' => $user->id,
            'title_snapshot' => 'title',
            'body_snapshot' => 'body',
        ]);

        return [$announcement, $recipient];
    }

    private function insertQueuePayload(string $payload): void
    {
        $now = now()->timestamp;
        $this->app['db']->table('jobs')->insert([
            'queue' => 'default',
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function payloadForDatabaseJob(string $recipientRowId, string $token): string
    {
        return json_encode([
            'displayName' => AnnouncementDatabaseNotificationJob::class,
            'data' => [
                'commandName' => AnnouncementDatabaseNotificationJob::class,
                'command' => serialize(new AnnouncementDatabaseNotificationJob($recipientRowId, $token)),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function payloadForMailJob(string $recipientRowId, string $token): string
    {
        return json_encode([
            'displayName' => AnnouncementMailNotificationJob::class,
            'data' => [
                'commandName' => AnnouncementMailNotificationJob::class,
                'command' => serialize(new AnnouncementMailNotificationJob($recipientRowId, $token)),
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
