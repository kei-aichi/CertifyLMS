<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\UseCases\Meeting\ReserveAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReserveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_reserved_meeting_and_consumes_quota(): void
    {
        Notification::fake();
        [$student, $coach, $enrollment, $scheduledAt] = $this->reservationFixture();

        $meeting = app(ReserveAction::class)($enrollment, $scheduledAt, '予約相談');

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved->value,
            'topic' => '予約相談',
        ]);
        $this->assertNotNull($meeting->meeting_quota_transaction_id);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'id' => $meeting->meeting_quota_transaction_id,
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
            'related_meeting_id' => $meeting->id,
        ]);
        Notification::assertSentTo($coach, MeetingReservedNotification::class);
    }

    public function test_selects_the_least_loaded_available_coach(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $lessLoaded = User::factory()->coach()->inProgress()->create();
        $moreLoaded = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $lessLoaded, $admin);
        $this->attachCoach($certification, $moreLoaded, $admin);
        $scheduledAt = $this->scheduledAt();
        CoachAvailability::factory()->forCoach($lessLoaded)->onDay($scheduledAt->dayOfWeek)->timeRange('09:00:00', '18:00:00')->create();
        CoachAvailability::factory()->forCoach($moreLoaded)->onDay($scheduledAt->dayOfWeek)->timeRange('09:00:00', '18:00:00')->create();
        Meeting::factory()->completed()->forCoach($moreLoaded)->create(['scheduled_at' => now()->subDays(2)]);
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $meeting = app(ReserveAction::class)($enrollment, $scheduledAt, '負荷分散');

        $this->assertSame($lessLoaded->id, $meeting->coach_id);
    }

    public function test_rejects_when_quota_is_insufficient_without_creating_records(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);
        [$enrollment, $scheduledAt] = $this->enrollmentFixture($student);

        $this->expectException(InsufficientMeetingQuotaException::class);

        try {
            app(ReserveAction::class)($enrollment, $scheduledAt, 'Quota不足');
        } finally {
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
        }
    }

    public function test_rejects_slot_outside_coach_availability(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 1]);
        [$enrollment, $scheduledAt] = $this->enrollmentFixture($student, false);

        $this->expectException(MeetingOutOfAvailabilityException::class);

        try {
            app(ReserveAction::class)($enrollment, $scheduledAt, '枠外');
        } finally {
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
        }
    }

    public function test_rejects_when_all_candidate_coaches_are_occupied(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 1]);
        [$enrollment, $scheduledAt, $coach] = $this->enrollmentFixture($student);
        Meeting::factory()->reserved()->forCoach($coach)->create(['scheduled_at' => $scheduledAt]);

        $this->expectException(MeetingNoAvailableCoachException::class);

        try {
            app(ReserveAction::class)($enrollment, $scheduledAt, '重複枠');
        } finally {
            $this->assertDatabaseCount('meetings', 1);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
        }
    }

    /** @return array{User, User, Enrollment, Carbon} */
    private function reservationFixture(): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 1]);
        [$enrollment, $scheduledAt, $coach] = $this->enrollmentFixture($student);

        return [$student, $coach, $enrollment, $scheduledAt];
    }

    /** @return array{Enrollment, Carbon, User} */
    private function enrollmentFixture(User $student, bool $withAvailability = true): array
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/room']);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $scheduledAt = $this->scheduledAt();
        if ($withAvailability) {
            CoachAvailability::factory()->forCoach($coach)->onDay($scheduledAt->dayOfWeek)->timeRange('09:00:00', '18:00:00')->create();
        }
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        return [$enrollment, $scheduledAt, $coach];
    }

    private function scheduledAt(): Carbon
    {
        return now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
    }

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
