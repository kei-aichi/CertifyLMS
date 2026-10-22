<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AnnouncementRecipientSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_recipient_schema_and_relationships_exist(): void
    {
        $this->assertTrue(Schema::hasTable('announcement_recipients'));
        $this->assertTrue(Schema::hasColumns('announcement_recipients', [
            'announcement_id',
            'recipient_id',
            'title_snapshot',
            'body_snapshot',
            'database_status',
            'mail_status',
            'database_dispatch_token',
            'mail_dispatch_token',
            'database_claimed_at',
            'mail_claimed_at',
            'database_queued_at',
            'mail_queued_at',
            'database_processing_started_at',
            'mail_processing_started_at',
            'database_completed_at',
            'mail_completed_at',
        ]));

        $announcement = Announcement::factory()->create();
        $recipient = User::factory()->student()->create();
        $snapshot = AnnouncementRecipient::create([
            'announcement_id' => $announcement->id,
            'recipient_id' => $recipient->id,
            'title_snapshot' => 'title',
            'body_snapshot' => 'body',
        ]);

        $this->assertTrue($snapshot->announcement->is($announcement));
        $this->assertTrue($snapshot->recipient->is($recipient));
        $snapshot->refresh();
        $this->assertSame('pending', $snapshot->database_status);
        $this->assertSame('pending', $snapshot->mail_status);
    }

    public function test_announcement_and_recipient_pair_is_unique(): void
    {
        $announcement = Announcement::factory()->create();
        $recipient = User::factory()->student()->create();
        $attributes = [
            'announcement_id' => $announcement->id,
            'recipient_id' => $recipient->id,
            'title_snapshot' => 'title',
            'body_snapshot' => 'body',
        ];

        AnnouncementRecipient::create($attributes);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AnnouncementRecipient::create($attributes);
    }
}
