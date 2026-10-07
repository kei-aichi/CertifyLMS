<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification as LaravelDatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ReservationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_reservation_notifies_saved_coach_with_meeting_data(): void
    {
        Mail::fake();
        [$student, $coach, $enrollment, $scheduledAt] = $this->reservationFixture();

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i:s'),
            'topic' => '予約通知テスト',
        ]);
        $response->assertRedirect();

        $meeting = Meeting::query()->sole();
        $notification = LaravelDatabaseNotification::query()
            ->where('notifiable_id', $coach->id)
            ->where('type', MeetingReservedNotification::class)
            ->sole();

        $this->assertSame($meeting->id, $notification->data['meeting_id']);
        $this->assertSame($student->id, $notification->data['student_user_id']);
        $this->assertSame($coach->id, $notification->data['coach_user_id']);
        $this->assertSame('meetings.show', $notification->data['redirect_route']);
        $this->assertSame(['meeting' => $meeting->id], $notification->data['redirect_parameters']);
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_inactive_saved_coach_does_not_receive_reservation_notification(): void
    {
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            [$student, $coach, $enrollment, $scheduledAt] = $this->reservationFixture($status);

            $this->actingAs($student)->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i:s'),
                'topic' => '予約通知対象外テスト',
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_transaction_rollback_does_not_create_meeting_or_notification(): void
    {
        Mail::fake();
        [$student, , $enrollment, $scheduledAt] = $this->reservationFixture();
        $event = 'eloquent.created: '.Meeting::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('meeting create failure');
        });

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        try {
            $this->actingAs($student)->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i:s'),
                'topic' => 'rollback test',
            ]);
        } finally {
            Event::forget($event);
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('notifications', 0);
        }
    }

    private function reservationFixture(UserStatus $coachStatus = UserStatus::InProgress): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create(['status' => $coachStatus]);
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
        CoachAvailability::factory()->forCoach($coach)->onDay($scheduledAt->dayOfWeek)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        return [$student, $coach, $enrollment, $scheduledAt];
    }
}
