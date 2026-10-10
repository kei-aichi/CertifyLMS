<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_assigned_coach_see_note_content_author_and_controls(): void
    {
        [$enrollment, $coach, $admin] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => "<b>確認事項</b>\n次回に確認",
        ]);

        foreach ([$coach, $admin] as $user) {
            $response = $this->actingAs($user)->get(route('enrollments.show', $enrollment));
            $response->assertOk()
                ->assertSee('コーチメモ')
                ->assertSee('確認事項')
                ->assertSee(e($coach->name), false)
                ->assertSee('whitespace-pre-line', false);
        }

        $this->actingAs($coach)->get(route('enrollments.show', $enrollment))
            ->assertSee(route('enrollment-notes.edit', $note), false)
            ->assertSee(route('enrollment-notes.destroy', $note), false);
    }

    public function test_student_does_not_see_note_section_content_or_operation_urls(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '内部メモ']);

        $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('コーチメモ')
            ->assertDontSee('内部メモ')
            ->assertDontSee(route('enrollment-notes.edit', $note), false)
            ->assertDontSee(route('enrollment-notes.destroy', $note), false);
    }

    public function test_soft_deleted_author_name_and_note_body_are_preserved_in_html(): void
    {
        [$enrollment, $coach, $admin] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => "<script>alert('x')</script>\n本文",
        ]);
        $authorId = $note->author_user_id;
        $note->author->delete();

        $response = $this->actingAs($admin)->get(route('enrollments.show', $enrollment));

        $response->assertOk()
            ->assertSee('本文')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert', false)
            ->assertSee(e($coach->name), false);
        $this->assertSame($authorId, $note->fresh()->author_user_id);
        $this->assertSame("<script>alert('x')</script>\n本文", $note->fresh()->body);
    }

    public function test_soft_deleted_enrollment_does_not_expose_notes(): void
    {
        [$enrollment, $coach, $admin] = $this->scenario();
        EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '削除済み親の内部メモ']);
        $enrollment->delete();

        foreach ([$coach, $admin] as $user) {
            $this->actingAs($user)->get(route('enrollments.show', $enrollment))
                ->assertOk()
                ->assertDontSee('削除済み親の内部メモ');
        }
    }

    public function test_soft_deleted_student_does_not_expose_notes(): void
    {
        [$enrollment, $coach, $admin] = $this->scenario();
        EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '削除済み受講生の内部メモ']);
        $enrollment->user->delete();

        foreach ([$coach, $admin] as $user) {
            $this->actingAs($user)->get(route('enrollments.show', $enrollment))
                ->assertOk()
                ->assertDontSee('削除済み受講生の内部メモ');
        }
    }

    /** @return array{0: Enrollment, 1: User, 2: User} */
    private function scenario(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        return [$enrollment, $coach, $admin];
    }
}
