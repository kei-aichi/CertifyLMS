<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_coach_can_view_create_update_and_delete_own_note(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $this->actingAs($coach);

        $this->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSee('コーチメモ');

        $this->post(route('enrollments.notes.store', $enrollment), ['body' => '最初のメモ'])
            ->assertRedirect(route('enrollments.show', $enrollment));
        $note = EnrollmentNote::query()->firstOrFail();
        $this->assertSame($coach->id, $note->author_user_id);

        $this->get(route('enrollment-notes.edit', $note))->assertOk()->assertSee('最初のメモ');
        $this->patch(route('enrollment-notes.update', $note), ['body' => '更新後のメモ'])
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '更新後のメモ']);

        $this->delete(route('enrollment-notes.destroy', $note))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_admin_can_manage_notes_and_student_cannot_see_or_manage_them(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '管理者用メモ']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('enrollments.show', $enrollment))
            ->assertOk()->assertSee('管理者用メモ');
        $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment))
            ->assertOk()->assertDontSee('コーチメモ')->assertDontSee('管理者用メモ');
        $this->actingAs($enrollment->user)->get(route('enrollment-notes.edit', $note))
            ->assertForbidden();
    }

    public function test_validation_error_does_not_create_note(): void
    {
        [$enrollment, $coach] = $this->scenario();

        $this->actingAs($coach)
            ->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.notes.store', $enrollment), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_notes_are_displayed_in_created_at_descending_order(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $old = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => '古いメモ',
            'created_at' => Carbon::parse('2026-01-01 09:00:00'),
        ]);
        $newest = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => '新しいメモ',
            'created_at' => Carbon::parse('2026-01-03 09:00:00'),
        ]);
        $middle = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => '中間のメモ',
            'created_at' => Carbon::parse('2026-01-02 09:00:00'),
        ]);

        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        $response->assertOk()->assertSeeInOrder([
            $newest->body,
            $middle->body,
            $old->body,
        ]);
    }

    public function test_note_authors_are_eager_loaded_without_one_query_per_note(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $otherCoach = User::factory()->coach()->create();
        $this->assign($enrollment, $otherCoach);
        EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->count(2)->create();
        EnrollmentNote::factory()->forEnrollment($enrollment)->by($otherCoach)->count(2)->create();
        $authorQueries = [];
        $authorIds = [$coach->id, $otherCoach->id];

        DB::listen(function ($query) use (&$authorQueries, $authorIds): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, ' from `users` ')
                && str_contains($sql, ' in (')
                && count(array_intersect($authorIds, $query->bindings)) === count($authorIds)) {
                $authorQueries[] = $query->sql;
            }
        });

        $this->actingAs($coach)->get(route('enrollments.show', $enrollment))->assertOk();

        $this->assertCount(1, $authorQueries);
    }

    public function test_unassigned_coach_cannot_access_note_routes(): void
    {
        [$enrollment, $assigned] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($assigned)->create();
        $other = User::factory()->coach()->create();

        $this->actingAs($other)->get(route('enrollments.show', $enrollment))->assertForbidden();
        $this->actingAs($other)->post(route('enrollments.notes.store', $enrollment), ['body' => '拒否'])
            ->assertForbidden();
        $this->actingAs($other)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($other)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
    }

    public function test_deleted_enrollment_and_student_reject_note_operations(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create();
        $enrollment->delete();

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), ['body' => '拒否'])
            ->assertNotFound();
        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertForbidden();

        $enrollment->restore();
        $enrollment->user->delete();
        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertForbidden();
    }

    /** @return array{0: Enrollment, 1: User} */
    private function scenario(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        return [$enrollment, $coach];
    }

    private function assign(Enrollment $enrollment, User $coach): void
    {
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $enrollment->certification_id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
