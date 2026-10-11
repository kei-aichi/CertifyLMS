<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\MeetingReminderDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
