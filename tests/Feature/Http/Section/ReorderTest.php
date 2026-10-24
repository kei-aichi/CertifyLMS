<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reorder_updates_orders_and_redirects_to_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $chapter = $this->makeChapter();
        $sections = Section::factory()->forChapter($chapter)->count(3)->sequence(
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        )->create();

        $this->actingAs($admin)
            ->patch(route('admin.chapters.sections.reorder', $chapter), ['ids' => $sections->reverse()->pluck('id')->values()->all()])
            ->assertRedirect(route('admin.chapters.show', $chapter));

        foreach ($sections->reverse()->values() as $index => $section) {
            $this->assertSame($index + 1, $section->fresh()->order);
        }
    }

    public function test_reorder_rejects_id_from_another_chapter_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $otherChapter = Chapter::factory()->forPart($chapter->part)->create();
        $foreign = Section::factory()->forChapter($otherChapter)->state(['order' => 1])->create();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => [...$sections->pluck('id')->all(), $foreign->id]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
        $this->assertSame(1, $foreign->fresh()->order);
    }

    public function test_reorder_rejects_nonexistent_ulid_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => [...$sections->pluck('id')->all(), (string) Str::ulid()]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_missing_id_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => $sections->take(2)->pluck('id')->all()])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_duplicate_id_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => [$sections[0]->id, $sections[0]->id, $sections[2]->id]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_invalid_id_format_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => [...$sections->pluck('id')->all(), 'invalid-id']])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_unassigned_coach_is_forbidden_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->coach()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => $sections->pluck('id')->all()])
            ->assertForbidden();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_student_is_forbidden_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->student()->create())
            ->patchJson(route('admin.chapters.sections.reorder', $chapter), ['ids' => $sections->pluck('id')->all()])
            ->assertForbidden();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_guest_is_redirected_to_login_without_changing_orders(): void
    {
        [$chapter, $sections] = $this->makeSections();
        $orders = $sections->pluck('order', 'id')->all();

        $this->patch(route('admin.chapters.sections.reorder', $chapter), ['ids' => $sections->pluck('id')->all()])
            ->assertRedirect(route('login'));

        $this->assertOrdersUnchanged($orders);
    }

    private function makeChapter(): Chapter
    {
        $part = Part::factory()->forCertification(Certification::factory()->published()->create())->create();

        return Chapter::factory()->forPart($part)->create();
    }

    /** @return array{Chapter, \Illuminate\Database\Eloquent\Collection<int, Section>} */
    private function makeSections(): array
    {
        $chapter = $this->makeChapter();
        $sections = Section::factory()->forChapter($chapter)->count(3)->sequence(
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        )->create();

        return [$chapter, $sections];
    }

    /** @param array<string, int> $orders */
    private function assertOrdersUnchanged(array $orders): void
    {
        foreach ($orders as $id => $order) {
            $this->assertSame($order, Section::findOrFail($id)->order);
        }
    }
}
