<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ListCoachMeetingsAction;
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

        $result = app(ListCoachMeetingsAction::class)($coach, []);

        $this->assertSame([$own->id], $result['meetings']->getCollection()->modelKeys());
        $this->assertSame('upcoming', $result['filter']);
    }

    public function test_filters_by_student_and_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->create();
        $match = Meeting::factory()->forCoach($coach)->forEnrollment($enrollment)->create();
        Meeting::factory()->forCoach($coach)->create();

        $action = app(ListCoachMeetingsAction::class);
        $byStudent = $action($coach, ['student' => $student->id]);
        $byEnrollment = $action($coach, ['enrollment' => $enrollment->id]);

        $this->assertSame([$match->id], $byStudent['meetings']->getCollection()->modelKeys());
        $this->assertSame([$match->id], $byEnrollment['meetings']->getCollection()->modelKeys());
    }

    public function test_supports_past_and_all_ordering_and_pagination(): void
    {
        $coach = User::factory()->coach()->create();
        $past = Meeting::factory()->forCoach($coach)->completed()->create(['scheduled_at' => now()->subDays(2)]);
        $future = Meeting::factory()->forCoach($coach)->inFuture()->create(['scheduled_at' => now()->addDays(2)]);

        $action = app(ListCoachMeetingsAction::class);
        $this->assertSame([$past->id], $action($coach, ['filter' => 'past'])['meetings']->getCollection()->modelKeys());
        $this->assertSame([$future->id, $past->id], $action($coach, ['filter' => 'all'])['meetings']->getCollection()->modelKeys());
    }
}
