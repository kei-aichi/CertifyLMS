<?php

declare(strict_types=1);

namespace Tests\Feature\Http\CoachStudent;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 統合後 `enrollments.index` のコーチ scope 観点テスト。
 * Policy + `Enrollment::scopeForUser` で担当資格の Enrollment のみが見えることを保証する。
 * admin / student の正常系・未認証拒否は `EnrollmentControllerTest` に集約済。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_sees_only_enrollments_in_assigned_certifications(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $assignedCert = Certification::factory()->published()->create(['name' => 'Assigned Cert']);
        $otherCert = Certification::factory()->published()->create(['name' => 'Other Cert']);

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $assignedCert->id,
        ]);
        Enrollment::factory()->learning()->create([
            'user_id' => $student->id,
            'certification_id' => $otherCert->id,
        ]);

        // Act
        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        // Assert
        $response->assertOk();
        $response->assertSee('Assigned Cert');
        $response->assertDontSee('Other Cert');
    }

    public function test_guest_cannot_access(): void
    {
        // Act
        $response = $this->get(route('enrollments.index'));

        // Assert
        $response->assertRedirect(route('login'));
    }

    public function test_coach_sees_enrollments_from_multiple_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certifications = Certification::factory()->published()->count(2)->create();

        foreach ($certifications as $certification) {
            CertificationCoachAssignment::create([
                'certification_id' => $certification->id,
                'user_id' => $coach->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);
            Enrollment::factory()->for($certification)->create();
        }

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertOk()->assertViewHas('enrollments', fn ($rows) => $rows->total() === 2);
    }

    public function test_coach_keeps_enrollment_visible_when_student_is_soft_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create([
            'name' => 'Deleted Student Name',
            'email' => 'deleted-student@example.test',
        ]);
        $certification = Certification::factory()->published()->create(['name' => 'Assigned Cert']);
        CertificationCoachAssignment::create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $student->delete();

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertOk()
            ->assertViewHas('enrollments', function ($rows) use ($enrollment) {
                return $rows->total() === 1
                    && $rows->first()->is($enrollment)
                    && $rows->first()->relationLoaded('user')
                    && $rows->first()->user === null;
            })
            ->assertSee('Assigned Cert')
            ->assertSee('>-<', false)
            ->assertDontSee('Deleted Student Name')
            ->assertDontSee('deleted-student@example.test');
    }

    public function test_coach_with_no_assigned_certifications_sees_empty_list(): void
    {
        $coach = User::factory()->coach()->create();
        Enrollment::factory()->count(2)->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertOk()
            ->assertViewHas('enrollments', fn ($rows) => $rows->total() === 0)
            ->assertSee('該当する受講登録がありません');
    }

    public function test_coach_does_not_see_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $assignment = CertificationCoachAssignment::factory()->unassigned()->create(['user_id' => $coach->id]);
        Enrollment::factory()->create(['certification_id' => $assignment->certification_id]);

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertOk()->assertViewHas('enrollments', fn ($rows) => $rows->isEmpty());
    }

    public function test_same_student_only_assigned_certification_is_visible(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $assigned = Certification::factory()->published()->create(['name' => 'Visible Cert']);
        $other = Certification::factory()->published()->create(['name' => 'Hidden Cert']);
        CertificationCoachAssignment::create([
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        Enrollment::factory()->for($student)->for($assigned)->create();
        Enrollment::factory()->for($student)->for($other)->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertSee('Visible Cert')->assertDontSee('Hidden Cert');
    }

    public function test_coach_cannot_retrieve_unassigned_certification_by_filter(): void
    {
        $coach = User::factory()->coach()->create();
        $other = Certification::factory()->published()->create();
        Enrollment::factory()->for($other)->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index', ['certification_id' => $other->id]));

        $response->assertOk()->assertViewHas('enrollments', fn ($rows) => $rows->total() === 0);
    }

    public function test_coach_can_filter_an_assigned_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        Enrollment::factory()->for($assigned)->create();
        Enrollment::factory()->for($other)->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index', ['certification_id' => $assigned->id]));

        $response->assertOk()->assertViewHas('enrollments', function ($rows) use ($assigned) {
            return $rows->total() === 1 && $rows->first()->certification_id === $assigned->id;
        });
    }

    public function test_keyword_and_status_filters_do_not_escape_coach_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        Enrollment::factory()->for($assigned)->for(User::factory()->state(['name' => 'Scope Target']))->learning()->create();
        Enrollment::factory()->for($other)->for(User::factory()->state(['name' => 'Scope Target']))->passed()->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index', [
            'keyword' => 'Scope Target',
            'status' => EnrollmentStatus::Passed->value,
        ]));

        $response->assertOk()->assertViewHas('enrollments', fn ($rows) => $rows->total() === 0);
    }

    public function test_pagination_and_total_count_use_only_coach_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        Enrollment::factory()->count(21)->for($assigned)->create();
        Enrollment::factory()->count(3)->for($other)->create();

        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertOk()->assertViewHas('enrollments', function ($rows) {
            return $rows->total() === 21 && $rows->count() === 20 && $rows->lastPage() === 2;
        });
    }

    public function test_coach_cannot_view_unassigned_enrollment_detail(): void
    {
        $coach = User::factory()->coach()->create();
        $other = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($other)->create();

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertForbidden();
    }
}
