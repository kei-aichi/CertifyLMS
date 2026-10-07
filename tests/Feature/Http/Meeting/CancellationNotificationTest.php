<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification as LaravelDatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class CancellationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cancellation_notifies_coach_and_excludes_student(): void
    {
        Mail::fake();
        [$student, $coach, $meeting] = $this->meetingFixture();

        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $notification = LaravelDatabaseNotification::query()
            ->where('notifiable_id', $coach->id)
            ->where('type', MeetingCanceledNotification::class)
            ->sole();

        $this->assertSame($meeting->id, $notification->data['meeting_id']);
        $this->assertSame($student->id, $notification->data['canceled_by_user_id']);
        $this->assertSame($student->name, $notification->data['canceled_by_name']);
        $this->assertSame('meetings.show', $notification->data['redirect_route']);
        $this->assertSame(['meeting' => $meeting->id], $notification->data['redirect_parameters']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_coach_cancellation_notifies_student_and_excludes_coach(): void
    {
        Mail::fake();
        [$student, $coach, $meeting] = $this->meetingFixture();

        $this->actingAs($coach)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $notification = LaravelDatabaseNotification::query()
            ->where('notifiable_id', $student->id)
            ->where('type', MeetingCanceledNotification::class)
            ->sole();

        $this->assertSame($coach->id, $notification->data['canceled_by_user_id']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $coach->id]);
    }

    public function test_only_in_progress_recipient_is_notified(): void
    {
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            [$student, $coach, $meeting] = $this->meetingFixture($status);

            $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();
            $this->assertDatabaseMissing('notifications', [
                'notifiable_id' => $coach->id,
                'type' => MeetingCanceledNotification::class,
            ]);
        }
    }

    public function test_rollback_does_not_send_cancellation_notification(): void
    {
        Mail::fake();
        [$student, $coach, $meeting] = $this->meetingFixture();
        $event = 'eloquent.updated: '.Meeting::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('cancel update failure');
        });

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        try {
            $this->actingAs($student)->post(route('meetings.cancel', $meeting));
        } finally {
            Event::forget($event);
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $coach->id]);
        }
    }

    /** @return array{0: User, 1: User, 2: Meeting} */
    private function meetingFixture(UserStatus $coachStatus = UserStatus::InProgress): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create(['status' => $coachStatus]);
        $meeting = Meeting::factory()->reserved()->forStudent($student)->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        return [$student, $coach, $meeting];
    }
}
