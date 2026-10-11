<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingReminder;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use App\UseCases\MeetingReminder\AcquireDispatchAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class AcquireDispatchActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_student_and_coach_can_acquire_the_same_window_independently(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        [$meeting, $student, $coach] = $this->createMeeting('2026-10-11 10:00:00');
        $action = new AcquireDispatchAction;

        $studentResult = $action($meeting->id, 'eve', $student->id);
        $coachResult = $action($meeting->id, 'eve', $coach->id);

        $this->assertTrue($studentResult->acquired());
        $this->assertTrue($coachResult->acquired());
        $this->assertSame(2, MeetingReminderDispatch::count());
    }

    public function test_only_the_matching_window_and_minute_is_accepted(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 10:00:30', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-10 11:00:45');
        $action = new AcquireDispatchAction;

        $this->assertTrue($action($meeting->id, 'one_hour_before', $student->id)->acquired());

        CarbonImmutable::setTestNow('2026-10-10 10:01:00', 'Asia/Tokyo');
        $this->assertTrue($action($meeting->id, 'one_hour_before', $student->id)->notEligible());
    }

    public function test_canceled_completed_and_started_meetings_are_skipped(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        $action = new AcquireDispatchAction;

        foreach ([MeetingStatus::Canceled, MeetingStatus::Completed] as $status) {
            [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00', $status);
            $this->assertTrue($action($meeting->id, 'eve', $student->id)->notEligible());
        }

        [$meeting, $student] = $this->createMeeting('2026-10-10 17:59:59');
        $this->assertTrue($action($meeting->id, 'eve', $student->id)->notEligible());
    }

    public function test_invalid_recipient_role_status_and_soft_deleted_user_are_skipped(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student, $coach] = $this->createMeeting('2026-10-11 10:00:00');
        $action = new AcquireDispatchAction;

        $admin = User::factory()->admin()->create();
        $invited = User::factory()->student()->invited()->create();
        $student->delete();

        $this->assertTrue($action($meeting->id, 'eve', $admin->id)->notEligible());
        $this->assertTrue($action($meeting->id, 'eve', $invited->id)->notEligible());
        $this->assertTrue($action($meeting->id, 'eve', $student->id)->notEligible());
        $this->assertTrue($action($meeting->id, 'eve', $coach->id)->acquired());
    }

    public function test_duplicate_acquisition_is_a_normal_skip_and_record_is_committed(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');
        $action = new AcquireDispatchAction;

        $first = $action($meeting->id, 'eve', $student->id);
        $second = $action($meeting->id, 'eve', $student->id);

        $this->assertTrue($first->acquired());
        $this->assertTrue($second->duplicate());
        $this->assertDatabaseHas('meeting_reminder_dispatches', [
            'meeting_id' => $meeting->id,
            'window' => 'eve',
            'recipient_id' => $student->id,
        ]);
        $this->assertSame(1, MeetingReminderDispatch::count());
    }

    public function test_only_the_target_mysql_unique_index_is_classified_as_duplicate(): void
    {
        $action = new AcquireDispatchAction;
        $method = new ReflectionMethod($action, 'isDispatchUniqueViolation');
        $method->setAccessible(true);

        $target = $this->queryException(
            'Duplicate entry for key meeting_reminder_dispatches_meeting_window_recipient_unique',
            1062,
        );
        $primaryKey = $this->queryException('Duplicate entry for PRIMARY', 1062);
        $otherTable = $this->queryException(
            'Duplicate entry for key another_table_unique',
            1062,
        );

        $this->assertTrue($method->invoke($action, $target));
        $this->assertFalse($method->invoke($action, $primaryKey));
        $this->assertFalse($method->invoke($action, $otherTable));
    }

    public function test_non_unique_mysql_error_is_not_classified_as_duplicate(): void
    {
        $action = new AcquireDispatchAction;
        $method = new ReflectionMethod($action, 'isDispatchUniqueViolation');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(
            $action,
            $this->queryException('Deadlock found when trying to get lock', 1213),
        ));
        $this->assertFalse($method->invoke(
            $action,
            $this->queryException('Connection lost', 2006),
        ));
    }

    public function test_sqlite_only_accepts_the_target_three_column_unique_constraint(): void
    {
        $action = new AcquireDispatchAction;
        $method = new ReflectionMethod($action, 'isDispatchUniqueViolation');
        $method->setAccessible(true);

        $target = $this->queryException(
            'UNIQUE constraint failed: meeting_reminder_dispatches.meeting_id, meeting_reminder_dispatches.window, meeting_reminder_dispatches.recipient_id',
            19,
        );
        $other = $this->queryException(
            'UNIQUE constraint failed: meeting_reminder_dispatches.id',
            19,
        );

        $this->assertTrue($method->invoke($action, $target));
        $this->assertFalse($method->invoke($action, $other));
    }

    public function test_db_exception_rolls_back_transaction_without_leaving_a_dispatch_record(): void
    {
        $this->expectException(QueryException::class);

        try {
            \DB::transaction(function (): void {
                MeetingReminderDispatch::create([
                    'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j4',
                    'window' => 'eve',
                    'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j5',
                ]);

                throw $this->queryException('Connection lost', 2006);
            });
        } finally {
            $this->assertDatabaseMissing('meeting_reminder_dispatches', [
                'meeting_id' => '01j9x0a1b2c3d4e5f6g7h8i9j4',
                'window' => 'eve',
                'recipient_id' => '01j9x0a1b2c3d4e5f6g7h8i9j5',
            ]);
        }
    }

    public function test_invalid_window_is_rejected_without_creating_a_record(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AcquireDispatchAction)('01j9x0a1b2c3d4e5f6g7h8i9j0', 'invalid', '01j9x0a1b2c3d4e5f6g7h8i9j1');
    }

    /** @return array{0: Meeting, 1: User, 2: User} */
    private function createMeeting(string $scheduledAt, MeetingStatus $status = MeetingStatus::Reserved): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()
            ->for($student, 'student')
            ->for($coach, 'coach')
            ->create([
                'scheduled_at' => $scheduledAt,
                'status' => $status->value,
            ]);

        return [$meeting, $student, $coach];
    }

    private function queryException(string $message, int $driverCode): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = ['HY000', $driverCode, $message];

        return new QueryException('mysql', 'insert into meeting_reminder_dispatches', [], $previous);
    }
}
