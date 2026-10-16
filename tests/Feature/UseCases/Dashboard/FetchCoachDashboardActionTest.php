<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Meeting;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionAnswer;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class FetchCoachDashboardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_enrollments_come_from_certification_coaches_pivot(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $myCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $this->attachCoach($myCert, $coach);

        $mine = Enrollment::factory()->for($myCert)->learning()->create();
        Enrollment::factory()->for($otherCert)->learning()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertCount(1, $vm->assignedEnrollments);
        $this->assertSame($mine->id, $vm->assignedEnrollments->first()->id);
    }

    public function test_assigned_enrollments_carry_last_activity_at_via_with_max(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        $enrollment = Enrollment::factory()->for($cert)->learning()->create();

        LearningSession::factory()
            ->forEnrollment($enrollment)
            ->forUser($enrollment->user)
            ->closed()
            ->startedOn(now()->subDay())
            ->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $first = $vm->assignedEnrollments->first();
        $this->assertNotNull($first->last_activity_at);
        $this->assertTrue(Carbon::parse((string) $first->last_activity_at)->equalTo(
            Carbon::parse((string) LearningSession::query()->where('enrollment_id', $enrollment->id)->value('started_at')),
        ));
    }

    public function test_last_activity_at_is_the_latest_started_at_across_sessions(): void
    {
        [$coach, , $enrollment] = $this->createAssignedEnrollment();
        $earlier = Carbon::parse('2026-01-01 10:00:00');
        $latest = Carbon::parse('2026-01-03 15:00:00');

        LearningSession::factory()->forEnrollment($enrollment)->startedOn($earlier)->create([
            'ended_at' => $earlier->copy()->addMinutes(5),
        ]);
        LearningSession::factory()->forEnrollment($enrollment)->startedOn($latest)->create([
            'ended_at' => $latest->copy()->addMinutes(5),
        ]);

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertTrue(Carbon::parse((string) $result->last_activity_at)->equalTo($latest));
    }

    public function test_last_activity_at_uses_started_at_when_it_differs_from_ended_at(): void
    {
        [$coach, , $enrollment] = $this->createAssignedEnrollment();
        $startedAt = Carbon::parse('2026-02-01 09:00:00');
        $endedAt = Carbon::parse('2026-02-01 18:00:00');

        LearningSession::factory()->forEnrollment($enrollment)->create([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertTrue(Carbon::parse((string) $result->last_activity_at)->equalTo($startedAt));
    }

    public function test_open_session_with_null_ended_at_is_included_in_last_activity_at(): void
    {
        [$coach, , $enrollment] = $this->createAssignedEnrollment();
        $startedAt = Carbon::parse('2026-03-01 11:00:00');

        LearningSession::factory()->forEnrollment($enrollment)->open()->create([
            'started_at' => $startedAt,
            'ended_at' => null,
        ]);

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertTrue(Carbon::parse((string) $result->last_activity_at)->equalTo($startedAt));
    }

    public function test_last_activity_at_is_null_when_enrollment_has_no_sessions(): void
    {
        [$coach, , $enrollment] = $this->createAssignedEnrollment();

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertNull($result->last_activity_at);
    }

    public function test_exercise_answer_time_is_not_used_for_last_activity_at(): void
    {
        [$coach, $cert, $enrollment] = $this->createAssignedEnrollment();
        [$part, $chapter, $section] = $this->createContentChain($cert);
        $question = SectionQuestion::factory()->forSection($section)->create();
        $startedAt = Carbon::parse('2026-04-01 09:00:00');
        $answeredAt = Carbon::parse('2026-04-02 09:00:00');

        LearningSession::factory()->forEnrollment($enrollment)->forSection($section)->create([
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(10),
        ]);
        SectionQuestionAnswer::factory()->forUser($enrollment->user)->forQuestion($question)->answeredOn($answeredAt)->create();

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertTrue(Carbon::parse((string) $result->last_activity_at)->equalTo($startedAt));
    }

    public function test_assigned_enrollments_eager_load_user_and_certification(): void
    {
        [$coach] = $this->createAssignedEnrollment();

        $result = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->first();

        $this->assertTrue($result->relationLoaded('user'));
        $this->assertTrue($result->relationLoaded('certification'));
    }

    public function test_only_passed_and_learning_enrollments_are_displayed(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        Enrollment::factory()->for($cert)->learning()->create();
        Enrollment::factory()->for($cert)->passed()->create(['passed_at' => now()]);
        Enrollment::factory()->for($cert)->failed()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $statuses = $vm->assignedEnrollments->map(fn (Enrollment $e) => $e->status)->all();
        $this->assertNotContains(EnrollmentStatus::Failed, $statuses);
    }

    public function test_today_and_tomorrow_meetings_are_scoped_to_coach(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($cert)->for($student)->learning()->create();

        Meeting::factory()->state([
            'coach_id' => $coach->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved,
            'scheduled_at' => now()->addHours(2),
        ])->create();
        Meeting::factory()->state([
            'coach_id' => $coach->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved,
            'scheduled_at' => now()->addDays(2),
        ])->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertCount(1, $vm->todayAndTomorrowMeetings);
    }

    public function test_unread_chat_count_uses_service(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $mock = Mockery::mock(ChatUnreadCountService::class);
        $mock->shouldReceive('roomCountForUser')->with(Mockery::on(fn (User $u) => $u->id === $coach->id))->andReturn(7);
        $this->app->instance(ChatUnreadCountService::class, $mock);

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertSame(7, $vm->unreadChatCount);
    }

    public function test_view_model_does_not_carry_v3_dropped_properties(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertFalse(property_exists($vm, 'aggregatedWeakCategories'));
        $this->assertFalse(property_exists($vm, 'recentEnrollmentNotes'));
        $this->assertFalse(property_exists($vm, 'stagnationList'));
    }

    public function test_safe_returns_null_when_unread_chat_service_throws(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $mock = Mockery::mock(ChatUnreadCountService::class);
        $mock->shouldReceive('roomCountForUser')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(ChatUnreadCountService::class, $mock);

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertNull($vm->unreadChatCount);
    }

    public function test_unread_chat_count_and_summary_exclude_coachs_own_messages(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();

        $ownEnrollment = Enrollment::factory()->for($student)->create();
        $ownRoom = ChatRoom::factory()->for($ownEnrollment)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $ownRoom->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $ownRoom->id,
            'sender_user_id' => $coach->id,
        ]);

        $unreadEnrollment = Enrollment::factory()->for($student)->create();
        $unreadRoom = ChatRoom::factory()->for($unreadEnrollment)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $unreadRoom->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $unreadRoom->id,
            'sender_user_id' => $student->id,
        ]);

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertSame(1, $vm->unreadChatCount);
        $this->assertCount(1, $vm->recentUnreadChatRooms);
        $this->assertSame($unreadRoom->id, $vm->recentUnreadChatRooms->first()->id);
    }

    /**
     * @return array{0: User, 1: Certification, 2: Enrollment}
     */
    private function createAssignedEnrollment(): array
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach);
        $enrollment = Enrollment::factory()->for($certification)->learning()->create();

        return [$coach, $certification, $enrollment];
    }

    /**
     * @return array{0: Part, 1: Chapter, 2: Section}
     */
    private function createContentChain(Certification $certification): array
    {
        $part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $chapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $section = Section::factory()->for($chapter)->create(['status' => ContentStatus::Published->value]);

        return [$part, $chapter, $section];
    }

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
