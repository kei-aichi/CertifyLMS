<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chapter;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_reorder_updates_orders_and_redirects_to_part(): void
    {
        $admin = User::factory()->admin()->create();
        $part = Part::factory()->forCertification(Certification::factory()->published()->create())->create();
        $chapters = Chapter::factory()->forPart($part)->count(3)->sequence(
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        )->create();

        $this->actingAs($admin)
            ->patch(route('admin.parts.chapters.reorder', $part), ['ids' => $chapters->reverse()->pluck('id')->values()->all()])
            ->assertRedirect(route('admin.parts.show', $part));

        foreach ($chapters->reverse()->values() as $index => $chapter) {
            $this->assertSame($index + 1, $chapter->fresh()->order);
        }
    }

    public function test_reorder_rejects_id_from_another_part_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $otherPart = Part::factory()->forCertification($part->certification)->create();
        $foreign = Chapter::factory()->forPart($otherPart)->state(['order' => 1])->create();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => [...$chapters->pluck('id')->all(), $foreign->id]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
        $this->assertSame(1, $foreign->fresh()->order);
    }

    public function test_reorder_rejects_nonexistent_ulid_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => [...$chapters->pluck('id')->all(), (string) Str::ulid()]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_missing_id_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => $chapters->take(2)->pluck('id')->all()])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_duplicate_id_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => [$chapters[0]->id, $chapters[0]->id, $chapters[2]->id]])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_reorder_rejects_invalid_id_format_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => [...$chapters->pluck('id')->all(), 'invalid-id']])
            ->assertUnprocessable();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_unassigned_coach_is_forbidden_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->coach()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => $chapters->pluck('id')->all()])
            ->assertForbidden();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_student_is_forbidden_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->actingAs(User::factory()->student()->create())
            ->patchJson(route('admin.parts.chapters.reorder', $part), ['ids' => $chapters->pluck('id')->all()])
            ->assertForbidden();

        $this->assertOrdersUnchanged($orders);
    }

    public function test_guest_is_redirected_to_login_without_changing_orders(): void
    {
        [$part, $chapters] = $this->makeChapters();
        $orders = $chapters->pluck('order', 'id')->all();

        $this->patch(route('admin.parts.chapters.reorder', $part), ['ids' => $chapters->pluck('id')->all()])
            ->assertRedirect(route('login'));

        $this->assertOrdersUnchanged($orders);
    }

    /** @return array{Part, \Illuminate\Database\Eloquent\Collection<int, Chapter>} */
    private function makeChapters(): array
    {
        $part = Part::factory()->forCertification(Certification::factory()->published()->create())->create();
        $chapters = Chapter::factory()->forPart($part)->count(3)->sequence(
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        )->create();

        return [$part, $chapters];
    }

    /** @param array<string, int> $orders */
    private function assertOrdersUnchanged(array $orders): void
    {
        foreach ($orders as $id => $order) {
            $this->assertSame($order, Chapter::findOrFail($id)->order);
        }
    }
}
