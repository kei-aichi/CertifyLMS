<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_contains_only_own_non_deleted_enrollment_goals_in_display_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $ownEnrollment = Enrollment::factory()->for($student)->learning()->create();
        $deletedEnrollment = Enrollment::factory()->for($student)->learning()->create();
        $otherEnrollment = Enrollment::factory()->for($other)->learning()->create();
        $deletedEnrollment->delete();

        $near = EnrollmentGoal::factory()->forEnrollment($ownEnrollment)->create(['title' => 'near', 'target_date' => '2026-11-01']);
        $achieved = EnrollmentGoal::factory()->forEnrollment($ownEnrollment)->achieved()->create(['title' => 'achieved', 'target_date' => '2026-10-01']);
        $deleted = EnrollmentGoal::factory()->forEnrollment($deletedEnrollment)->create(['title' => 'deleted']);
        $otherGoal = EnrollmentGoal::factory()->forEnrollment($otherEnrollment)->create(['title' => 'other']);

        $viewModel = app(FetchStudentDashboardAction::class)($student);

        $this->assertSame([$near->id, $achieved->id], $viewModel->goalTimeline->pluck('id')->all());
        $this->assertFalse($viewModel->goalTimeline->contains('id', $deleted->id));
        $this->assertFalse($viewModel->goalTimeline->contains('id', $otherGoal->id));
    }
}
