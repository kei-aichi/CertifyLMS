<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EnrollmentGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_and_casts_are_available(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => '2026-12-31',
            'achieved_at' => '2026-10-08 10:00:00',
        ]);

        $fresh = $goal->fresh();

        $this->assertTrue($fresh->enrollment->is($enrollment));
        $this->assertTrue($enrollment->fresh()->goals->first()->is($fresh));
        $this->assertInstanceOf(Carbon::class, $fresh->target_date);
        $this->assertInstanceOf(Carbon::class, $fresh->achieved_at);
        $this->assertTrue($fresh->isAchieved());
    }

    public function test_null_achieved_at_means_not_achieved(): void
    {
        $goal = EnrollmentGoal::factory()->create(['achieved_at' => null]);

        $this->assertFalse($goal->fresh()->isAchieved());
    }

    public function test_display_order_is_unachieved_then_due_date_then_created_at(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $oldUnachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'old unachieved',
            'target_date' => '2026-11-01',
            'created_at' => '2026-10-01 10:00:00',
        ]);
        $newUnachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'new unachieved',
            'target_date' => '2026-11-01',
            'created_at' => '2026-10-02 10:00:00',
        ]);
        $near = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'near',
            'target_date' => '2026-10-10',
            'created_at' => '2026-10-03 10:00:00',
        ]);
        $noDate = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => 'no date',
            'target_date' => null,
            'created_at' => '2026-10-04 10:00:00',
        ]);
        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'title' => 'achieved',
            'target_date' => '2026-10-09',
            'created_at' => '2026-10-05 10:00:00',
        ]);

        $ordered = $enrollment->goals()->displayOrder()->pluck('id')->all();

        $this->assertSame([
            $near->id,
            $newUnachieved->id,
            $oldUnachieved->id,
            $noDate->id,
            $achieved->id,
        ], $ordered);
    }

    public function test_soft_deleting_enrollment_keeps_goal_and_force_delete_cascades(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $enrollment->delete();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);

        $enrollment->forceDelete();
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_display_order_places_dated_goals_before_undated_goals_for_each_state_and_breaks_id_ties(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $createdAt = '2026-10-08 10:00:00';

        $undatedUnachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'title' => 'undated unachieved',
            'target_date' => null,
            'created_at' => $createdAt,
        ]);
        $datedUnachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            'title' => 'dated unachieved',
            'target_date' => '2026-10-10',
            'created_at' => $createdAt,
        ]);
        $sameTargetLower = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAS',
            'title' => 'same target lower id',
            'target_date' => '2026-10-10',
            'created_at' => $createdAt,
        ]);
        $undatedAchieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
            'title' => 'undated achieved',
            'target_date' => null,
            'created_at' => $createdAt,
        ]);
        $datedAchieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAY',
            'title' => 'dated achieved',
            'target_date' => '2026-10-10',
            'created_at' => $createdAt,
        ]);

        $this->assertSame([
            $datedUnachieved->id,
            $sameTargetLower->id,
            $undatedUnachieved->id,
            $datedAchieved->id,
            $undatedAchieved->id,
        ], $enrollment->goals()->displayOrder()->pluck('id')->all());
    }
}
