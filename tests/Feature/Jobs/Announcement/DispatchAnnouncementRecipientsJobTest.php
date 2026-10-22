<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Announcement;

use App\Jobs\Announcement\DispatchAnnouncementRecipientsJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DispatchAnnouncementRecipientsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_database_and_mail_jobs_for_snapshot_recipients(): void
    {
        Queue::fake();
        $announcement = Announcement::factory()->create();
        $users = User::factory()->student()->count(101)->create();

        foreach ($users as $user) {
            AnnouncementRecipient::create([
                'announcement_id' => $announcement->id,
                'recipient_id' => $user->id,
                'title_snapshot' => 'title',
                'body_snapshot' => 'body',
            ]);
        }

        (new DispatchAnnouncementRecipientsJob($announcement->id))->handle();

        $this->assertSame(101, AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('database_status', 'pending')
            ->whereNotNull('database_queued_at')
            ->count());
        $this->assertSame(101, AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('mail_status', 'pending')
            ->whereNotNull('mail_queued_at')
            ->count());
        Queue::assertPushed(\App\Jobs\Announcement\AnnouncementDatabaseNotificationJob::class, 101);
        Queue::assertPushed(\App\Jobs\Announcement\AnnouncementMailNotificationJob::class, 101);
    }
}
