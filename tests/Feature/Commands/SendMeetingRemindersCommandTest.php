<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

final class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_eve_at_18_00_processes_next_day_meetings_for_both_recipients(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        [$meeting] = $this->createMeeting('2026-10-11 10:00:00');
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        $this->assertSame(2, MeetingReminderDispatch::where('meeting_id', $meeting->id)->count());
    }

    public function test_missed_eve_minute_does_not_backfill(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:01:00', 'Asia/Tokyo');
        $this->createMeeting('2026-10-11 10:00:00');
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('meeting_reminder_dispatches', 0);
    }

    public function test_eve_at_18_00_59_processes_next_day_meetings(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:59', 'Asia/Tokyo');
        [$meeting] = $this->createMeeting('2026-10-11 10:00:00');
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        $this->assertSame(2, MeetingReminderDispatch::where('meeting_id', $meeting->id)->count());
    }

    public function test_canceled_and_completed_meetings_are_not_sent(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $canceled = $this->createMeeting('2026-10-11 10:00:00', MeetingStatus::Canceled)[0];
        $completed = $this->createMeeting('2026-10-11 11:00:00', MeetingStatus::Completed)[0];
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('meeting_reminder_dispatches', ['meeting_id' => $canceled->id]);
        $this->assertDatabaseMissing('meeting_reminder_dispatches', ['meeting_id' => $completed->id]);
    }

    public function test_more_than_one_hundred_meetings_are_processed_without_duplicates_or_gaps(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $meetingIds = [];
        for ($index = 0; $index < 101; $index++) {
            $meetingIds[] = $this->createMeeting('2026-10-11 10:00:00')[0]->id;
        }
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        $this->assertSame(202, MeetingReminderDispatch::count());
        $this->assertSame(101, MeetingReminderDispatch::distinct('meeting_id')->count('meeting_id'));
        $this->assertSame(101, count(array_unique($meetingIds)));
    }

    public function test_one_hour_before_only_processes_the_target_minute(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 10:00:59', 'Asia/Tokyo');
        [$meeting] = $this->createMeeting('2026-10-10 11:00:30');
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0);

        $this->assertSame(2, MeetingReminderDispatch::where('meeting_id', $meeting->id)->count());

        CarbonImmutable::setTestNow('2026-10-10 10:01:00', 'Asia/Tokyo');
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0);
        $this->assertSame(2, MeetingReminderDispatch::where('meeting_id', $meeting->id)->count());
    }

    public function test_summary_is_reset_between_repeated_command_invocations(): void
    {
        $eveNow = CarbonImmutable::parse('2026-10-10 18:00:30', 'Asia/Tokyo');
        $oneHourNow = CarbonImmutable::parse('2026-10-11 10:00:30', 'Asia/Tokyo');
        [$eveMeeting, $eveStudent, $eveCoach] = $this->createMeeting('2026-10-11 10:00:00');
        [$oneHourMeeting, $oneHourStudent, $oneHourCoach] = $this->createMeeting('2026-10-11 11:00:00');

        foreach ([
            ['meeting_id' => $eveMeeting->id, 'window' => 'eve', 'recipient_id' => $eveStudent->id],
            ['meeting_id' => $eveMeeting->id, 'window' => 'eve', 'recipient_id' => $eveCoach->id],
            ['meeting_id' => $oneHourMeeting->id, 'window' => 'eve', 'recipient_id' => $oneHourStudent->id],
            ['meeting_id' => $oneHourMeeting->id, 'window' => 'eve', 'recipient_id' => $oneHourCoach->id],
            ['meeting_id' => $oneHourMeeting->id, 'window' => 'one_hour_before', 'recipient_id' => $oneHourStudent->id],
            ['meeting_id' => $oneHourMeeting->id, 'window' => 'one_hour_before', 'recipient_id' => $oneHourCoach->id],
        ] as $dispatch) {
            MeetingReminderDispatch::create($dispatch);
        }
        Notification::fake();

        CarbonImmutable::setTestNow($eveNow);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('Meeting reminders processed: acquired=0, not_eligible=0, duplicate=4, skipped=0, failed=0.')
            ->assertExitCode(0);

        CarbonImmutable::setTestNow($oneHourNow);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('Meeting reminders processed: acquired=0, not_eligible=0, duplicate=2, skipped=0, failed=0.')
            ->assertExitCode(0);

        $this->assertSame(6, MeetingReminderDispatch::count());
        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_invalid_window_returns_failure_without_sending(): void
    {
        Notification::fake();

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'invalid'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('meeting_reminder_dispatches', 0);
    }

    public function test_database_failure_returns_nonzero_and_mail_is_still_attempted(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->times(4)->ordered()->andReturnUsing(
            function ($recipient, $notification, $channels): void {
                if ($channels === ['database']) {
                    throw new RuntimeException('database failed');
                }
            },
        );

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(1);
    }

    public function test_mail_failure_returns_nonzero_while_database_result_is_kept(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->times(4)->ordered()->andReturnUsing(
            function ($recipient, $notification, $channels): void {
                if ($channels === ['mail']) {
                    throw new RuntimeException('mail failed');
                }
            },
        );

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(1);

        $this->assertSame(2, MeetingReminderDispatch::count());
    }

    public function test_both_channel_failures_return_nonzero(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $this->createMeeting('2026-10-11 10:00:00');
        Notification::shouldReceive('sendNow')->times(4)->andThrow(new RuntimeException('channel failed'));

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(1);
    }

    public function test_student_failure_does_not_prevent_coach_processing(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30', 'Asia/Tokyo');
        $this->createMeeting('2026-10-11 10:00:00');
        $calls = 0;
        Notification::shouldReceive('sendNow')->times(4)->ordered()->andReturnUsing(
            function () use (&$calls): void {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('student database failed');
                }
            },
        );

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(1);
        $this->assertSame(4, $calls);
    }

    public function test_scheduler_registers_both_commands_every_minute(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->filter();

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'notifications:send-meeting-reminders --window=eve')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'notifications:send-meeting-reminders --window=one_hour_before')));
    }

    /** @return array{0: Meeting, 1: User, 2: User} */
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

        return [$meeting, $student, $coach];
    }
}
