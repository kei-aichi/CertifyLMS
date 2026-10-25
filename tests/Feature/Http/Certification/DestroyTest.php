<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Models\Certification;
use App\Models\Certificate;
use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\Part;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\QuestionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_draft_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->draft()->create();

        $response = $this->actingAs($admin)->delete(route('admin.certifications.destroy', $cert));

        $response->assertRedirect(route('admin.certifications.index'));
        $this->assertDatabaseMissing('certifications', ['id' => $cert->id]);
    }

    public function test_cannot_delete_published_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();

        $response = $this->actingAs($admin)->deleteJson(route('admin.certifications.destroy', $cert));

        $response->assertStatus(409);
        $this->assertDatabaseHas('certifications', [
            'id' => $cert->id,
        ]);
    }

    public function test_cannot_delete_archived_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->archived()->create();

        $response = $this->actingAs($admin)->deleteJson(route('admin.certifications.destroy', $cert));

        $response->assertStatus(409);
    }

    public function test_enrollment_blocks_deletion_even_after_soft_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->draft()->create();
        $enrollment = Enrollment::factory()->for($cert)->create();
        $this->actingAs($admin)->deleteJson(route('admin.certifications.destroy', $cert))->assertConflict();
        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);

        // 画面上の受講登録が0件でも、履歴がDBに残っている間は物理削除を許可しない。
        $enrollment->delete();
        $this->assertSame(0, $cert->enrollments()->count());
        $this->deleteJson(route('admin.certifications.destroy', $cert))->assertConflict();
        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);
        $this->assertSoftDeleted($enrollment);
    }

    public function test_qa_foreign_key_rejection_is_conflict_and_preserves_posts(): void
    {
        $cert = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->for($cert)->create();
        $reply = QaReply::factory()->for($thread, 'qaThread')->create();
        $this->assertSame(0, Enrollment::withTrashed()->count());

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.certifications.destroy', $cert))->assertConflict();
        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_part_blocks_deletion_and_is_preserved(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->draft()->create();
        $part = Part::factory()->forCertification($cert)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.certifications.destroy', $cert))
            ->assertConflict()
            ->assertJsonPath('message', '関連する受講履歴・質問・教材データが存在するため、削除できません。');

        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);
        $this->assertDatabaseHas('parts', ['id' => $part->id]);
    }

    public function test_part_rejection_redirects_back_with_flash_error_for_html(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->draft()->create();
        Part::factory()->forCertification($cert)->create();

        $response = $this->from(route('admin.certifications.show', $cert))
            ->actingAs($admin)
            ->delete(route('admin.certifications.destroy', $cert));

        $response->assertRedirect(route('admin.certifications.show', $cert));
        $response->assertSessionHas(
            'error',
            '関連する受講履歴・質問・教材データが存在するため、削除できません。',
        );
        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);
    }

    public function test_each_restricted_certification_relation_blocks_deletion(): void
    {
        $admin = User::factory()->admin()->create();

        $cases = [
            function (Certification $cert): array {
                $mockExam = MockExam::factory()->forCertification($cert)->create();

                return [['table' => 'mock_exams', 'id' => $mockExam->id]];
            },
            function (Certification $cert): array {
                $category = QuestionCategory::factory()->forCertification($cert)->create();

                return [['table' => 'question_categories', 'id' => $category->id]];
            },
            function (Certification $cert): array {
                $enrollment = Enrollment::factory()->for($cert)->passed()->create();

                $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

                // Enrollmentが先に削除を拒否するため、Certificate FK単独の検証ではない。
                return [
                    ['table' => 'enrollments', 'id' => $enrollment->id],
                    ['table' => 'certificates', 'id' => $certificate->id],
                ];
            },
            function (Certification $cert): array {
                $announcement = Announcement::factory()->state([
                    'target_certification_id' => $cert->id,
                ])->create();

                return [['table' => 'announcements', 'id' => $announcement->id]];
            },
        ];

        foreach ($cases as $createRelatedData) {
            $cert = Certification::factory()->draft()->create();
            $relatedRecords = $createRelatedData($cert);

            $this->actingAs($admin)
                ->deleteJson(route('admin.certifications.destroy', $cert))
                ->assertConflict();

            $this->assertDatabaseHas('certifications', ['id' => $cert->id]);

            foreach ($relatedRecords as $relatedRecord) {
                $this->assertDatabaseHas($relatedRecord['table'], ['id' => $relatedRecord['id']]);
            }
        }
    }

    public function test_coach_assignment_only_does_not_block_deletion(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->draft()->create();
        $cert->coaches()->attach($coach->id, [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.certifications.destroy', $cert))
            ->assertRedirect(route('admin.certifications.index'));

        $this->assertDatabaseMissing('certifications', ['id' => $cert->id]);
    }

    public function test_coach_cannot_delete(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->draft()->create();

        $response = $this->actingAs($coach)->delete(route('admin.certifications.destroy', $cert));

        $response->assertForbidden();
    }
}
