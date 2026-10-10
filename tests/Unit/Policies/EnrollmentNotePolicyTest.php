<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_create_update_and_delete_active_enrollment_notes(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $admin = User::factory()->admin()->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($admin, $enrollment));
        $this->assertTrue($policy->create($admin, $enrollment));
        $this->assertTrue($policy->update($admin, $note));
        $this->assertTrue($policy->delete($admin, $note));
    }

    public function test_assigned_coach_can_view_and_create_but_only_author_can_update_or_delete(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $this->assign($enrollment->certification, $coach);
        $this->assign($enrollment->certification, $otherCoach);
        $note->update(['author_user_id' => $coach->id]);
        $otherNote = EnrollmentNote::factory()->forEnrollment($enrollment)->by($otherCoach)->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($coach, $enrollment));
        $this->assertTrue($policy->create($coach, $enrollment));
        $this->assertTrue($policy->update($coach, $note));
        $this->assertTrue($policy->delete($coach, $note));
        $this->assertFalse($policy->update($coach, $otherNote));
        $this->assertFalse($policy->delete($coach, $otherNote));
    }

    public function test_student_and_unassigned_coach_are_denied(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $student = $enrollment->user;
        $coach = User::factory()->coach()->create();
        $policy = new EnrollmentNotePolicy;

        foreach ([$student, $coach] as $user) {
            $this->assertFalse($policy->viewAny($user, $enrollment));
            $this->assertFalse($policy->create($user, $enrollment));
            $this->assertFalse($policy->update($user, $note));
            $this->assertFalse($policy->delete($user, $note));
        }
    }

    public function test_detached_coach_is_denied_even_for_own_note(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $coach = User::factory()->coach()->create();
        $this->assign($enrollment->certification, $coach);
        $note->update(['author_user_id' => $coach->id]);
        CertificationCoachAssignment::query()
            ->where('certification_id', $enrollment->certification_id)
            ->where('user_id', $coach->id)
            ->update(['unassigned_at' => now()]);
        $policy = new EnrollmentNotePolicy;

        $this->assertFalse($policy->viewAny($coach, $enrollment));
        $this->assertFalse($policy->create($coach, $enrollment));
        $this->assertFalse($policy->update($coach, $note));
        $this->assertFalse($policy->delete($coach, $note));
    }

    public function test_soft_deleted_enrollment_is_denied_for_admin_and_coach(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $this->assign($enrollment->certification, $coach);
        $enrollment->delete();
        $policy = new EnrollmentNotePolicy;

        foreach ([$admin, $coach] as $user) {
            $this->assertFalse($policy->viewAny($user, $enrollment));
            $this->assertFalse($policy->create($user, $enrollment));
            $this->assertFalse($policy->update($user, $note));
            $this->assertFalse($policy->delete($user, $note));
        }
    }

    public function test_soft_deleted_student_is_denied_for_admin_and_coach(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $this->assign($enrollment->certification, $coach);
        $enrollment->user->delete();
        $policy = new EnrollmentNotePolicy;

        foreach ([$admin, $coach] as $user) {
            $this->assertFalse($policy->viewAny($user, $enrollment));
            $this->assertFalse($policy->create($user, $enrollment));
            $this->assertFalse($policy->update($user, $note));
            $this->assertFalse($policy->delete($user, $note));
        }
    }

    public function test_soft_deleted_author_is_visible_but_not_editable_by_other_coach(): void
    {
        [$enrollment, $note] = $this->noteScenario();
        $coach = User::factory()->coach()->create();
        $this->assign($enrollment->certification, $coach);
        $author = $note->author;
        $authorName = $author->name;
        $note->author->delete();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($coach, $enrollment));
        $this->assertNotNull($note->author);
        $this->assertSame($authorName, $note->author->name);
        $this->assertFalse($policy->update($coach, $note));
        $this->assertFalse($policy->delete($coach, $note));

        $admin = User::factory()->admin()->create();
        $this->assertTrue($policy->update($admin, $note));
        $this->assertTrue($policy->delete($admin, $note));
    }

    /** @return array{0: Enrollment, 1: EnrollmentNote} */
    private function noteScenario(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        return [$enrollment, EnrollmentNote::factory()->forEnrollment($enrollment)->create()];
    }

    private function assign(Certification $certification, User $coach): void
    {
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
