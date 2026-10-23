<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\MeetingReminderDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingReminderDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_table_has_expected_columns_and_no_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('meeting_reminder_dispatches'));
        $this->assertTrue(Schema::hasColumns('meeting_reminder_dispatches', [
            'id', 'meeting_id', 'window', 'recipient_id', 'created_at', 'updated_at',
            'legacy', 'database_status', 'mail_status',
            'database_dispatch_token', 'mail_dispatch_token',
            'database_queued_at', 'mail_queued_at',
            'database_processing_started_at', 'mail_processing_started_at',
            'database_completed_at', 'mail_completed_at',
        ]));

        $this->assertSame([], Schema::getForeignKeys('meeting_reminder_dispatches'));
    }

    public function test_duplicate_meeting_window_recipient_is_rejected(): void
    {
        $attributes = [
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j0',
            'window' => 'eve',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j1',
        ];

        MeetingReminderDispatch::create($attributes);

        $this->expectException(QueryException::class);
        MeetingReminderDispatch::create($attributes);
    }

    public function test_same_meeting_and_recipient_can_have_different_windows(): void
    {
        $base = [
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j2',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j3',
        ];

        MeetingReminderDispatch::create([...$base, 'window' => 'eve']);
        MeetingReminderDispatch::create([...$base, 'window' => 'one_hour_before']);

        $this->assertDatabaseCount('meeting_reminder_dispatches', 2);
    }

    public function test_synchronous_dispatch_defaults_to_legacy_and_is_not_queueable(): void
    {
        $dispatch = MeetingReminderDispatch::create([
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j4',
            'window' => 'eve',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j5',
        ]);

        self::assertTrue($dispatch->isLegacy());
        self::assertFalse($dispatch->isQueueable());
        self::assertNull($dispatch->database_status);
        self::assertNull($dispatch->mail_status);
        self::assertNull($dispatch->database_queued_at);
        self::assertNull($dispatch->mail_queued_at);
    }

    public function test_legacy_dispatch_is_explicitly_not_queueable(): void
    {
        $dispatch = MeetingReminderDispatch::create([
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j6',
            'window' => 'eve',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j7',
            'legacy' => true,
            'database_status' => null,
            'mail_status' => null,
        ]);

        self::assertTrue($dispatch->isLegacy());
        self::assertFalse($dispatch->isQueueable());
        self::assertNull($dispatch->database_status);
        self::assertNull($dispatch->mail_status);
    }

    public function test_queue_dispatch_can_explicitly_opt_in_to_pending_state(): void
    {
        $dispatch = MeetingReminderDispatch::create([
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j8',
            'window' => 'eve',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j9',
            'legacy' => false,
            'database_status' => 'pending',
            'mail_status' => 'pending',
        ]);

        self::assertFalse($dispatch->isLegacy());
        self::assertTrue($dispatch->isQueueable());
        self::assertSame('pending', $dispatch->database_status);
        self::assertSame('pending', $dispatch->mail_status);
    }

    public function test_query_builder_insert_uses_synchronous_legacy_defaults(): void
    {
        DB::table('meeting_reminder_dispatches')->insert([
            'id' => '01j9x0a1b2c3d4e5f6g7h8i9k0',
            'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9k1',
            'window' => 'eve',
            'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9k2',
        ]);

        $row = DB::table('meeting_reminder_dispatches')->where('id', '01j9x0a1b2c3d4e5f6g7h8i9k0')->first();
        self::assertSame(1, (int) $row->legacy);
        self::assertNull($row->database_status);
        self::assertNull($row->mail_status);
    }
}
