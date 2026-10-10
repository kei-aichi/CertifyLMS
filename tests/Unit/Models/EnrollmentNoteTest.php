<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_and_factory_are_available(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $author = User::factory()->coach()->create(['name' => '担当コーチ']);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($author)->create([
            'body' => '受講生の学習状況を確認する。',
        ]);

        $fresh = $note->fresh();

        $this->assertTrue($fresh->enrollment->is($enrollment));
        $this->assertTrue($fresh->author->is($author));
        $this->assertTrue($enrollment->fresh()->notes->first()->is($fresh));
        $this->assertSame('受講生の学習状況を確認する。', $fresh->body);
        $this->assertFalse(method_exists($fresh, 'trashed'));
    }

    public function test_soft_deleted_enrollment_keeps_note(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->create();

        $enrollment->delete();

        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public function test_force_deleted_enrollment_cascades_note(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->create();

        $enrollment->forceDelete();

        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_soft_deleted_author_remains_available_through_relation(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $author = User::factory()->coach()->create(['name' => '退職コーチ']);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($author)->create();

        $author->delete();

        $this->assertSoftDeleted('users', ['id' => $author->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
        $this->assertSame('退職コーチ', $note->fresh()->author->name);
    }

    public function test_force_deleting_author_is_restricted_by_note_foreign_key(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $author = User::factory()->coach()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->by($author)->create();

        try {
            $author->forceDelete();
            $this->fail('投稿者Userの物理削除が許可されました。');
        } catch (QueryException) {
            // enrollment_notes.author_user_id のRESTRICTを確認する。
        }

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public function test_soft_deleted_student_keeps_enrollment_and_note(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->create();

        $student->delete();

        $this->assertSoftDeleted('users', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'user_id' => $student->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }
}
