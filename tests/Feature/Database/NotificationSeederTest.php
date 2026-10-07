<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class NotificationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_seeder_creates_expected_data_idempotently_without_mail(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('notifications', 24);
        $this->assertSame(6, DatabaseNotification::where('type', ChatMessageReceivedNotification::class)->count());
        $this->assertSame(6, DatabaseNotification::where('type', QaReplyReceivedNotification::class)->count());
        $this->assertSame(6, DatabaseNotification::where('type', MeetingReservedNotification::class)->count());
        $this->assertSame(6, DatabaseNotification::where('type', MeetingCanceledNotification::class)->count());
        $this->assertSame(12, DatabaseNotification::whereNull('read_at')->count());
        $this->assertSame(12, DatabaseNotification::whereNotNull('read_at')->count());
        $this->assertGreaterThan(1, DatabaseNotification::query()->select('created_at')->distinct()->count());
        Mail::assertNothingSent();

        $this->seed(NotificationSeeder::class);

        $this->assertDatabaseCount('notifications', 24);
        Mail::assertNothingSent();
    }
}
