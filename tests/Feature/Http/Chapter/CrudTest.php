<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_creates_draft_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.parts.chapters.store', $part), ['title' => '第1章'])
            ->assertRedirect();

        $chapter = Chapter::where('title', '第1章')->firstOrFail();
        $this->assertSame('draft', $chapter->status->value);
        $this->assertSame(1, $chapter->order);
    }

    public function test_publish_then_unpublish(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.publish', $chapter))
            ->assertRedirect();
        $this->assertSame('published', $chapter->fresh()->status->value);

        $this->actingAs($admin)
            ->post(route('admin.chapters.unpublish', $chapter))
            ->assertRedirect();
        $this->assertSame('draft', $chapter->fresh()->status->value);
    }

    public function test_destroy_draft_only(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertStatus(409);
    }

    public function test_admin_can_delete_draft_chapter_without_sections(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect(route('admin.parts.show', $part));

        $this->assertDatabaseMissing('chapters', ['id' => $chapter->id]);
    }

    public function test_cannot_delete_draft_chapter_with_sections_and_keeps_related_data(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();
        $section = \App\Models\Section::factory()->forChapter($chapter)->draft()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Chapterに子データまたは関連データが存在するため削除できません。']);

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_html_delete_with_sections_redirects_back_with_flash_error(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();
        \App\Models\Section::factory()->forChapter($chapter)->draft()->create();

        $this->actingAs($admin)
            ->from(route('admin.chapters.show', $chapter))
            ->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect(route('admin.chapters.show', $chapter))
            ->assertSessionHas('error', 'Chapterに子データまたは関連データが存在するため削除できません。');
    }

    public function test_guest_cannot_delete_chapter(): void
    {
        $chapter = $this->makeDraftChapter();

        $this->delete(route('admin.chapters.destroy', $chapter))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
    }

    public function test_student_cannot_delete_chapter(): void
    {
        $chapter = $this->makeDraftChapter();

        $this->actingAs(User::factory()->student()->create())
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertForbidden();

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
    }

    public function test_unassigned_coach_cannot_delete_chapter(): void
    {
        $chapter = $this->makeDraftChapter();

        $this->actingAs(User::factory()->coach()->create())
            ->deleteJson(route('admin.chapters.destroy', $chapter))
            ->assertForbidden();

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id]);
    }

    private function makeDraftChapter(): Chapter
    {
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();

        return Chapter::factory()->forPart($part)->draft()->create();
    }
}
