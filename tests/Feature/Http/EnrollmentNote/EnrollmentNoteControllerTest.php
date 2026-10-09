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
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', 'メモを追加しました。');
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

    public function test_admin_can_manage_notes_across_enrollments_students_and_certifications(): void
    {
        [$firstEnrollment, $coach] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $secondStudent = User::factory()->student()->inProgress()->create();
        $secondEnrollment = Enrollment::factory()->for($secondStudent)->learning()->create();
        $firstNote = EnrollmentNote::factory()->forEnrollment($firstEnrollment)->by($coach)->create(['body' => '資格Aのメモ']);
        $secondNote = EnrollmentNote::factory()->forEnrollment($secondEnrollment)->by($coach)->create(['body' => '資格Bのメモ']);

        $this->actingAs($admin)->get(route('enrollments.show', $firstEnrollment))
            ->assertOk()->assertSee($firstNote->body);
        $this->actingAs($admin)->get(route('enrollments.show', $secondEnrollment))
            ->assertOk()->assertSee($secondNote->body);

        $this->actingAs($admin)->patch(route('enrollment-notes.update', $secondNote), ['body' => '資格Bの修正'])
            ->assertRedirect(route('enrollments.show', $secondEnrollment));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $secondNote->id, 'body' => '資格Bの修正']);

        $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $firstNote))
            ->assertRedirect(route('enrollments.show', $firstEnrollment));
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $firstNote->id]);
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

    public function test_whitespace_only_body_is_rejected_for_store_and_update(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '変更前']);

        foreach (['   ', '　　', "\n\t\n", " \t　\n"] as $body) {
            $this->actingAs($coach)
                ->from(route('enrollments.show', $enrollment))
                ->post(route('enrollments.notes.store', $enrollment), ['body' => $body])
                ->assertSessionHasErrors('body');

            $this->actingAs($coach)
                ->from(route('enrollments.show', $enrollment))
                ->patch(route('enrollment-notes.update', $note), ['body' => $body])
                ->assertSessionHasErrors('body');
        }

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '変更前']);
        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_valid_body_boundaries_are_accepted_for_store_and_update(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create(['body' => '変更前']);
        $body = "　通常の本文 \t";
        $trimmedBody = '通常の本文';

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('あ', 2000)])
            ->assertRedirect();
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('あ', 2000)])
            ->assertRedirect();
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => $body])
            ->assertRedirect();

        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('あ', 2001)])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => $trimmedBody]);
    }

    public function test_html_validation_keeps_errors_and_old_input(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $body = str_repeat('a', 2001);

        $this->actingAs($coach)
            ->from(route('enrollments.show', $enrollment))
            ->post(route('enrollments.notes.store', $enrollment), ['body' => $body])
            ->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHasErrors('body')
            ->assertSessionHas('errors')
            ->assertSessionHas('_old_input.body', $body);
    }

    public function test_json_validation_returns_422_and_does_not_create_note(): void
    {
        [$enrollment, $coach] = $this->scenario();

        $this->actingAs($coach)
            ->postJson(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_unauthenticated_and_invalid_method_are_rejected_safely(): void
    {
        [$enrollment] = $this->scenario();

        $this->get(route('enrollments.show', $enrollment))->assertRedirect(route('login'));
        $this->get(route('enrollments.notes.store', $enrollment))->assertMethodNotAllowed();
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_missing_enrollment_and_note_return_404(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $missingEnrollment = (string) Str::ulid();
        $missingNote = (string) Str::ulid();

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $missingEnrollment), ['body' => '不在'])
            ->assertNotFound();
        $this->actingAs($coach)->get(route('enrollment-notes.edit', $missingNote))->assertNotFound();
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $missingNote), ['body' => '不在'])
            ->assertNotFound();
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $missingNote))->assertNotFound();
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

    public function test_note_list_reuses_eager_loaded_relations_for_authors_and_policy(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $otherCoach = User::factory()->coach()->create();
        $this->assign($enrollment, $otherCoach);
        $smallNotes = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create();

        $smallQueryCounts = $this->noteRelationQueryCounts($enrollment, $coach);

        EnrollmentNote::factory()->forEnrollment($enrollment)->by($otherCoach)->count(19)->create();
        $largeQueryCounts = $this->noteRelationQueryCounts($enrollment, $coach);

        $this->assertSame(1, $smallQueryCounts['authors'], json_encode($smallQueryCounts['sql'], JSON_THROW_ON_ERROR));
        $this->assertSame($smallQueryCounts['authors'], $largeQueryCounts['authors'], json_encode($largeQueryCounts['sql'], JSON_THROW_ON_ERROR));
        $this->assertSame($smallQueryCounts['enrollments'], $largeQueryCounts['enrollments'], json_encode($largeQueryCounts['sql'], JSON_THROW_ON_ERROR));
        $this->assertSame($smallQueryCounts['students'], $largeQueryCounts['students'], json_encode($largeQueryCounts['sql'], JSON_THROW_ON_ERROR));
        $this->assertSame($smallQueryCounts['coaches'], $largeQueryCounts['coaches'], json_encode($largeQueryCounts['sql'], JSON_THROW_ON_ERROR));

        unset($smallNotes);
    }

    /**
     * Count only the relation queries used by the note list and its Policy checks.
     * The same enrollment is rendered with one and twenty notes; a per-note
     * relation lookup would therefore increase one of these counts.
     *
     * @return array{authors:int,enrollments:int,students:int,coaches:int,sql:array<string,list<string>>}
     */
    private function noteRelationQueryCounts(Enrollment $enrollment, User $coach): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, ' from `users`')
                || str_contains($sql, ' from `enrollments`')
                || str_contains($sql, ' from `certification_coach_assignments`')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($coach)->get(route('enrollments.show', $enrollment))->assertOk();

        $counts = ['authors' => 0, 'enrollments' => 0, 'students' => 0, 'coaches' => 0];
        foreach ($queries as $sql) {
            $normalized = strtolower($sql);
            if (str_contains($normalized, ' from `users` inner join `certification_coach_assignments`')) {
                $counts['coaches']++;
            } elseif (str_contains($normalized, ' from `users`')
                && str_contains($normalized, ' in (')
                && ! str_contains($normalized, 'deleted_at` is null')) {
                $counts['authors']++;
            } elseif (str_contains($normalized, ' from `enrollments`') && str_contains($normalized, ' in (')) {
                $counts['enrollments']++;
            } elseif (str_contains($normalized, ' from `users`')) {
                $counts['students']++;
            }
        }

        return [...$counts, 'sql' => ['relation' => $queries]];
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
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => '拒否'])
            ->assertForbidden();
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();

        $enrollment->restore();
        $enrollment->user->delete();
        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), ['body' => '拒否'])
            ->assertForbidden();
        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => '拒否'])
            ->assertForbidden();
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
    }

    public function test_student_cannot_use_any_note_route(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create();
        $student = $enrollment->user;

        $this->actingAs($student)->post(route('enrollments.notes.store', $enrollment), ['body' => '拒否'])
            ->assertForbidden()->assertSessionMissing('success');
        $this->actingAs($student)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($student)->patch(route('enrollment-notes.update', $note), ['body' => '拒否'])
            ->assertForbidden()->assertSessionMissing('success');
        $this->actingAs($student)->delete(route('enrollment-notes.destroy', $note))
            ->assertForbidden()->assertSessionMissing('success');
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => $note->body]);
    }

    public function test_update_only_changes_body_and_preserves_note_metadata(): void
    {
        [$enrollment, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($coach)->create([
            'body' => '更新前',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $createdAt = $note->created_at;
        $authorId = $note->author_user_id;
        $enrollmentId = $note->enrollment_id;

        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), [
            'body' => '更新後',
            'author_user_id' => User::factory()->coach()->create()->id,
            'enrollment_id' => Enrollment::factory()->create()->id,
            'created_at' => now()->addYear()->toDateTimeString(),
        ])->assertRedirect();

        $fresh = $note->fresh();
        $this->assertSame('更新後', $fresh->body);
        $this->assertSame($authorId, $fresh->author_user_id);
        $this->assertSame($enrollmentId, $fresh->enrollment_id);
        $this->assertTrue($fresh->created_at->equalTo($createdAt));
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
