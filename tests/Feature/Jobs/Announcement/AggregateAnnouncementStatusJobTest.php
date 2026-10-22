<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Announcement;

use App\Enums\AnnouncementDispatchStatus;
use App\Jobs\Announcement\AggregateAnnouncementStatusJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AggregateAnnouncementStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_channels_succeeded_is_succeeded(): void
    {
        [$announcement, $row] = $this->announcementWithRecipient();
        $row->update(['database_status' => 'succeeded', 'mail_status' => 'succeeded']);

        (new AggregateAnnouncementStatusJob($announcement->id))->handle();

        $this->assertSame(AnnouncementDispatchStatus::Succeeded, $announcement->fresh()->dispatch_status);
    }

    public function test_incomplete_recipient_is_processing(): void
    {
        [$announcement] = $this->announcementWithRecipient();

        (new AggregateAnnouncementStatusJob($announcement->id))->handle();

        $this->assertSame(AnnouncementDispatchStatus::Processing, $announcement->fresh()->dispatch_status);
    }

    public function test_failure_has_priority_over_incomplete_rows(): void
    {
        [$announcement, $failed] = $this->announcementWithRecipient();
        $failed->update(['database_status' => 'failed']);
        $user = User::factory()->student()->create();
        AnnouncementRecipient::create([
            'announcement_id' => $announcement->id,
            'recipient_id' => $user->id,
            'title_snapshot' => 'title',
            'body_snapshot' => 'body',
        ]);

        (new AggregateAnnouncementStatusJob($announcement->id))->handle();

        $this->assertSame(AnnouncementDispatchStatus::Failed, $announcement->fresh()->dispatch_status);
    }

    public function test_zero_recipients_is_succeeded(): void
    {
        $announcement = Announcement::factory()->create(['dispatch_status' => 'processing']);

        (new AggregateAnnouncementStatusJob($announcement->id))->handle();

        $this->assertSame(AnnouncementDispatchStatus::Succeeded, $announcement->fresh()->dispatch_status);
    }

    public function test_repeated_aggregation_does_not_roll_back_state(): void
    {
        [$announcement, $row] = $this->announcementWithRecipient();
        $row->update(['database_status' => 'failed']);
        $job = new AggregateAnnouncementStatusJob($announcement->id);
        $job->handle();
        $job->handle();

        $this->assertSame(AnnouncementDispatchStatus::Failed, $announcement->fresh()->dispatch_status);
    }

    /** @return array{Announcement, AnnouncementRecipient} */
    private function announcementWithRecipient(): array
    {
        $announcement = Announcement::factory()->create(['dispatch_status' => 'processing']);
        $user = User::factory()->student()->create();
        $row = AnnouncementRecipient::create([
            'announcement_id' => $announcement->id,
            'recipient_id' => $user->id,
            'title_snapshot' => 'title',
            'body_snapshot' => 'body',
        ]);

        return [$announcement, $row];
    }
}
