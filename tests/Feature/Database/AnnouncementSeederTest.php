<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Database\Seeders\AnnouncementSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AnnouncementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_seeder_creates_three_targets_and_zero_recipient_history_idempotently(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('announcements', 4);
        $this->assertSame(1, Announcement::where('target_type', AnnouncementTargetType::AllStudents->value)->count());
        $this->assertSame(2, Announcement::where('target_type', AnnouncementTargetType::Certification->value)->count());
        $this->assertSame(1, Announcement::where('target_type', AnnouncementTargetType::User->value)->count());
        $this->assertSame(1, Announcement::where('dispatched_count', 0)->count());
        $announcementNotifications = DatabaseNotification::query()
            ->where('type', AnnouncementNotification::class)
            ->get();
        $this->assertGreaterThanOrEqual(3, $announcementNotifications->count());
        $this->assertCount(
            $announcementNotifications->count(),
            $announcementNotifications->unique(fn ($notification) => $notification->notifiable_type.'|'.$notification->notifiable_id.'|'.$notification->data['announcement_id']),
        );
        foreach (Announcement::all() as $announcement) {
            $notifications = $announcementNotifications->where('data.announcement_id', $announcement->id);
            $this->assertCount($announcement->dispatched_count, $notifications);
            $this->assertCount(0, $notifications->where('notifiable_id', null));
        }
        $emptyAnnouncement = Announcement::where('dispatched_count', 0)->firstOrFail();
        $this->assertFalse($announcementNotifications->contains(fn ($notification) => $notification->data['announcement_id'] === $emptyAnnouncement->id));
        $this->assertGreaterThan(0, User::where('status', '!=', 'in_progress')->count());
        $this->assertGreaterThan(0, Enrollment::whereIn('status', ['passed', 'failed'])->count());
        $this->assertGreaterThan(0, Enrollment::onlyTrashed()->count());
        Mail::assertNothingSent();

        $announcementCount = Announcement::count();
        $notificationCount = DatabaseNotification::where('type', AnnouncementNotification::class)->count();
        $this->seed(AnnouncementSeeder::class);

        $this->assertSame($announcementCount, Announcement::count());
        $this->assertSame($notificationCount, DatabaseNotification::where('type', AnnouncementNotification::class)->count());
        Mail::assertNothingSent();
    }
}
