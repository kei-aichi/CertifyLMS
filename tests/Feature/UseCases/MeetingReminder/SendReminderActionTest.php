<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingReminder;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use App\UseCases\MeetingReminder\SendReminderAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

final class SendReminderActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_acquired_record_sends_database_then_mail_and_persists_database_notification(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');

        $result = app(SendReminderAction::class)(
            $meeting->id,
            'eve',
            $student->id,
        );

        $this->assertSame('acquired', $result->acquisitionStatus);
        $this->assertSame('success', $result->databaseStatus);
        $this->assertSame('success', $result->mailStatus);
        $this->assertNotNull($result->notificationId);
        $this->assertDatabaseHas('meeting_reminder_dispatches', [
            'meeting_id' => $meeting->id,
            'window' => 'eve',
            'recipient_id' => $student->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $result->notificationId,
            'notifiable_id' => $student->id,
        ]);
    }

    public function test_not_eligible_and_duplicate_do_not_send(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00', MeetingStatus::Canceled);
        Notification::shouldReceive('sendNow')->never();

        $result = app(SendReminderAction::class)($meeting->id, 'eve', $student->id);
        $this->assertSame('not_eligible', $result->acquisitionStatus);
    }

    public function test_duplicate_after_an_acquired_send_does_not_send_again(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->twice()->andReturn(null);

        $action = app(SendReminderAction::class);
        $action($meeting->id, 'eve', $student->id);
        $result = $action($meeting->id, 'eve', $student->id);

        $this->assertSame('duplicate', $result->acquisitionStatus);
    }

    public function test_database_failure_does_not_prevent_mail_and_record_remains(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->once()->ordered()->andThrow(new RuntimeException('database failed'));
        Notification::shouldReceive('sendNow')->once()->ordered()->andReturn(null);

        $result = app(SendReminderAction::class)($meeting->id, 'eve', $student->id);

        $this->assertSame('failed', $result->databaseStatus);
        $this->assertSame('success', $result->mailStatus);
        $this->assertSame(1, MeetingReminderDispatch::count());
    }

    public function test_mail_failure_does_not_remove_successful_database_notification(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->once()->ordered()->andReturn(null);
        Notification::shouldReceive('sendNow')->once()->ordered()->andThrow(new RuntimeException('mail failed'));

        $result = app(SendReminderAction::class)($meeting->id, 'eve', $student->id);

        $this->assertSame('success', $result->databaseStatus);
        $this->assertSame('failed', $result->mailStatus);
        $this->assertSame(1, MeetingReminderDispatch::count());
    }

    public function test_both_channel_failures_are_reported_independently_with_the_same_uuid(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-11 10:00:00');
        $ids = [];
        $objects = [];
        Notification::shouldReceive('sendNow')->twice()->ordered()->andReturnUsing(
            function ($recipient, $notification) use (&$ids, &$objects): void {
                $ids[] = $notification->id;
                $objects[] = spl_object_id($notification);
                throw new RuntimeException('channel failed');
            },
        );

        $result = app(SendReminderAction::class)($meeting->id, 'eve', $student->id);

        $this->assertSame('failed', $result->databaseStatus);
        $this->assertSame('failed', $result->mailStatus);
        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame($objects[0], $objects[1]);
        $this->assertSame($result->notificationId, $ids[0]);
        $this->assertSame(1, MeetingReminderDispatch::count());
    }

    public function test_channel_start_is_skipped_after_meeting_has_started(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 10:00:00', 'Asia/Tokyo');
        [$meeting, $student] = $this->createMeeting('2026-10-10 11:00:00');
        $action = app(SendReminderAction::class);

        CarbonImmutable::setTestNow('2026-10-10 11:00:01', 'Asia/Tokyo');
        Notification::shouldReceive('sendNow')->never();

        // The acquisition itself is also rejected once the meeting has started.
        $result = $action($meeting->id, 'one_hour_before', $student->id);

        $this->assertSame('not_eligible', $result->acquisitionStatus);
        $this->assertSame(0, MeetingReminderDispatch::count());
    }

    /** @return array{0: Meeting, 1: User} */
    private function createMeeting(
        string $scheduledAt,
        MeetingStatus $status = MeetingStatus::Reserved,
    ): array {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()
            ->for($student, 'student')
            ->for($coach, 'coach')
            ->create([
                'scheduled_at' => $scheduledAt,
                'status' => $status->value,
            ]);

        return [$meeting, $student];
    }
}
