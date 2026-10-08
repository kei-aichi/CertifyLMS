<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\UseCases\Enrollment\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowActionGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_action_loads_goals_in_display_order(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $late = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['target_date' => '2027-01-01']);
        $near = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['target_date' => '2026-11-01']);
        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create(['target_date' => '2026-10-01']);

        $result = app(ShowAction::class)($enrollment);

        $this->assertSame([$near->id, $late->id, $achieved->id], $result->goals->pluck('id')->all());
    }

    public function test_soft_deleted_enrollment_keeps_goals_in_db_but_hides_them_from_detail(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $enrollment->delete();

        $result = app(ShowAction::class)(Enrollment::withTrashed()->findOrFail($enrollment->id));

        $this->assertTrue($result->goals->isEmpty());
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }
}
