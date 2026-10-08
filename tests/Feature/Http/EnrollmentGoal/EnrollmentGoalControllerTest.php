<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_goal_without_accepting_managed_fields(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '資格取得', 'description' => '学習する', 'target_date' => '2026-12-31',
            'enrollment_id' => 'forged', 'achieved_at' => now()->toDateTimeString(), 'user_id' => 'forged',
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '資格取得',
            'achieved_at' => null,
        ]);
    }

    public function test_owner_can_edit_update_achieve_unachieve_and_destroy_goal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['achieved_at' => null]);

        $this->actingAs($student)->get(route('enrollment-goals.edit', $goal))->assertOk();
        $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '更新後', 'description' => null, 'target_date' => now()->subDay()->toDateString(),
        ])->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id, 'title' => '更新後', 'achieved_at' => null]);

        $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal))->assertRedirect();
        $achievedAt = $goal->fresh()->achieved_at;
        $this->assertNotNull($achievedAt);
        $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal))->assertRedirect();
        $this->assertTrue($goal->fresh()->achieved_at->greaterThanOrEqualTo($achievedAt));
        $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertRedirect();
        $this->assertNull($goal->fresh()->achieved_at);
        $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertRedirect();
        $this->assertNull($goal->fresh()->achieved_at);
        $this->actingAs($student)->delete(route('enrollment-goals.destroy', $goal))->assertRedirect();
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_non_owner_and_inactive_student_cannot_modify_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        foreach ([$other, User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
            $this->actingAs($user)->delete(route('enrollment-goals.destroy', $goal))->assertForbidden();
        }
        foreach (['invited', 'graduated', 'withdrawn'] as $status) {
            $inactive = User::factory()->student()->state(['status' => $status])->create();
            $this->actingAs($inactive)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
        }
    }

    public function test_soft_deleted_enrollment_goal_routes_are_forbidden_and_store_is_not_allowed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $enrollment->delete();

        $this->actingAs($student)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
        $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => 'x', 'description' => null, 'target_date' => null,
        ])->assertForbidden();
        $this->actingAs($student)->delete(route('enrollment-goals.destroy', $goal))->assertForbidden();
        $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
        $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertForbidden();
        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'x', 'description' => null, 'target_date' => null,
        ])->assertNotFound();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $this->get(route('enrollment-goals.edit', $goal))->assertRedirect(route('login'));
    }
}
