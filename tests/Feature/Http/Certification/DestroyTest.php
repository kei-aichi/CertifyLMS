<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
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

    public function test_coach_cannot_delete(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->draft()->create();

        $response = $this->actingAs($coach)->delete(route('admin.certifications.destroy', $cert));

        $response->assertForbidden();
    }
}
