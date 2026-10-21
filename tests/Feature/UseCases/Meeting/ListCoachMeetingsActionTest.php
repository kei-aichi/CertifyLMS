<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ListCoachMeetingsAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListCoachMeetingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_assigned_coachs_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        $own = Meeting::factory()->forCoach($coach)->create();
        Meeting::factory()->create();

        $result = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, null);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame([$own->id], $result->getCollection()->modelKeys());
    }

    public function test_filters_by_student_and_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->create();
        $match = Meeting::factory()->forCoach($coach)->forEnrollment($enrollment)->create();
        Meeting::factory()->forCoach($coach)->create();

        $action = app(ListCoachMeetingsAction::class);
        $byStudent = $action($coach, 'upcoming', $student->id, null);
        $byEnrollment = $action($coach, 'upcoming', null, $enrollment->id);

        $this->assertSame([$match->id], $byStudent->getCollection()->modelKeys());
        $this->assertSame([$match->id], $byEnrollment->getCollection()->modelKeys());
    }

    public function test_supports_past_and_all_ordering_and_pagination(): void
    {
        $coach = User::factory()->coach()->create();
        $past = Meeting::factory()->forCoach($coach)->completed()->create(['scheduled_at' => now()->subDays(2)]);
        $future = Meeting::factory()->forCoach($coach)->inFuture()->create(['scheduled_at' => now()->addDays(2)]);

        $action = app(ListCoachMeetingsAction::class);
        $this->assertSame([$past->id], $action($coach, 'past', null, null)->getCollection()->modelKeys());
        $this->assertSame([$future->id, $past->id], $action($coach, 'all', null, null)->getCollection()->modelKeys());
    }

    public function test_orders_upcoming_meetings_by_scheduled_at_ascending(): void
    {
        $coach = User::factory()->coach()->create();
        $late = Meeting::factory()->forCoach($coach)->create(['scheduled_at' => now()->addDays(3)->startOfHour()]);
        $early = Meeting::factory()->forCoach($coach)->create(['scheduled_at' => now()->addDays(1)->startOfHour()]);

        $result = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, null);

        $this->assertSame([$early->id, $late->id], $result->getCollection()->modelKeys());
    }

    public function test_paginates_twenty_one_upcoming_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        Meeting::factory()->forCoach($coach)->count(21)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
        ]);

        $result = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, null);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(20, $result->perPage());
        $this->assertSame(21, $result->total());
        $this->assertCount(20, $result->getCollection());
        $this->assertSame(2, $result->lastPage());
    }
}
