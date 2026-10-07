<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_manage_own_goal_when_in_progress(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->view($student, $goal));
        $this->assertTrue($policy->create($student, $enrollment));
        $this->assertTrue($policy->update($student, $goal));
        $this->assertTrue($policy->delete($student, $goal));
        $this->assertTrue($policy->markAchieved($student, $goal));
        $this->assertTrue($policy->unmarkAchieved($student, $goal));
    }

    public function test_other_student_cannot_access_or_manage_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertFalse($policy->view($other, $goal));
        $this->assertFalse($policy->create($other, $enrollment));
        $this->assertFalse($policy->update($other, $goal));
        $this->assertFalse($policy->delete($other, $goal));
        $this->assertFalse($policy->markAchieved($other, $goal));
        $this->assertFalse($policy->unmarkAchieved($other, $goal));
    }

    public function test_assigned_coach_can_view_but_cannot_manage(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $enrollment = Enrollment::factory()->for($certification)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->view($coach, $goal));
        $this->assertFalse($policy->create($coach, $enrollment));
        $this->assertFalse($policy->update($coach, $goal));
        $this->assertFalse($policy->delete($coach, $goal));
        $this->assertFalse($policy->markAchieved($coach, $goal));
        $this->assertFalse($policy->unmarkAchieved($coach, $goal));
    }

    public function test_unassigned_coach_and_admin_cannot_manage(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertFalse($policy->view($coach, $goal));
        $this->assertTrue($policy->view($admin, $goal));
        foreach (['create', 'update', 'delete', 'markAchieved', 'unmarkAchieved'] as $ability) {
            $this->assertFalse($policy->{$ability}($admin, $ability === 'create' ? $enrollment : $goal));
        }
    }

    public function test_soft_deleted_enrollment_is_denied_for_all_roles(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $enrollment->delete();
        $goal = $goal->fresh();
        $policy = app(EnrollmentGoalPolicy::class);

        foreach ([$student, User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            $this->assertFalse($policy->view($user, $goal));
            $this->assertFalse($policy->update($user, $goal));
            $this->assertFalse($policy->delete($user, $goal));
            $this->assertFalse($policy->markAchieved($user, $goal));
            $this->assertFalse($policy->unmarkAchieved($user, $goal));
        }
        $this->assertFalse($policy->create($student, $enrollment));
    }

    public function test_student_status_other_than_in_progress_cannot_manage_or_view_own_goal(): void
    {
        $policy = app(EnrollmentGoalPolicy::class);

        foreach (['invited', 'graduated', 'withdrawn'] as $state) {
            $student = User::factory()->student()->state(['status' => $state])->create();
            $enrollment = Enrollment::factory()->for($student)->learning()->create();
            $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

            $this->assertFalse($policy->view($student, $goal));
            $this->assertFalse($policy->create($student, $enrollment));
            $this->assertFalse($policy->update($student, $goal));
        }
    }
}
