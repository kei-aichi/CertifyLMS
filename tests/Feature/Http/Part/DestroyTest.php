<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Part;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_soft_delete_draft_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->delete(route('admin.parts.destroy', $part))
            ->assertRedirect(route('admin.certifications.parts.index', $cert));

        $this->assertDatabaseMissing('parts', ['id' => $part->id]);
    }

    public function test_cannot_delete_published_part(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.parts.destroy', $part))
            ->assertStatus(409);

        $this->assertDatabaseHas('parts', ['id' => $part->id]);
    }

    public function test_cannot_delete_draft_part_with_chapters_and_keeps_related_data(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.parts.destroy', $part))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Partに子データまたは関連データが存在するため削除できません。']);

        $this->assertDatabaseHas('parts', ['id' => $part->id]);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
    }

    public function test_html_delete_with_chapters_redirects_back_with_flash_error(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->from(route('admin.parts.show', $part))
            ->delete(route('admin.parts.destroy', $part))
            ->assertRedirect(route('admin.parts.show', $part))
            ->assertSessionHas('error', 'Partに子データまたは関連データが存在するため削除できません。');
    }

    public function test_guest_cannot_delete_part(): void
    {
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->delete(route('admin.parts.destroy', $part))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('parts', ['id' => $part->id]);
    }

    public function test_student_cannot_delete_part(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($student)
            ->deleteJson(route('admin.parts.destroy', $part))
            ->assertForbidden();

        $this->assertDatabaseHas('parts', ['id' => $part->id]);
    }

    public function test_unassigned_coach_cannot_delete_part(): void
    {
        $coach = User::factory()->coach()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($coach)
            ->deleteJson(route('admin.parts.destroy', $part))
            ->assertForbidden();

        $this->assertDatabaseHas('parts', ['id' => $part->id]);
    }
}
