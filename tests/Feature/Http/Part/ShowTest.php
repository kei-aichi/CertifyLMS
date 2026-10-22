<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\UseCases\Learning\ShowPartAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 詳細画面(PartController::show)の検証。
 * Part 詳細に表示される配下 Chapter 一覧が並び順(order 昇順)で返ることを確認する。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapters_are_listed_in_order_ascending(): void
    {
        // Arrange: order を登録順とずらして Chapter を作成
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        Chapter::factory()->forPart($part)->published()->create(['order' => 3]);
        Chapter::factory()->forPart($part)->published()->create(['order' => 1]);
        Chapter::factory()->forPart($part)->published()->create(['order' => 2]);

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.parts.show', $part));

        // Assert: 登録順(3,1,2)ではなく order 昇順(1,2,3)で並ぶ
        $response->assertOk();
        $this->assertSame(
            [1, 2, 3],
            $response->viewData('part')->chapters->pluck('order')->all(),
            'Part 詳細の Chapter 一覧は order 昇順で並ぶはず(登録順ではない)',
        );
    }

    public function test_completed_by_chapter_is_empty_without_enrollment_but_section_totals_are_set(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        Section::factory()->forChapter($chapter)->published()->count(2)->create();

        $result = app(ShowPartAction::class)($part, $student);

        $this->assertSame([], $result['completedByChapter']);
        $this->assertSame(2, $result['chapters']->first()->sections_total_count);
    }

    public function test_completed_by_chapter_contains_done_count_with_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $done = Section::factory()->forChapter($chapter)->published()->create();
        Section::factory()->forChapter($chapter)->published()->create();
        $enrollment = $student->enrollments()->create([
            'certification_id' => $cert->id,
            'status' => EnrollmentStatus::Learning,
        ]);
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($done)->create();

        $result = app(ShowPartAction::class)($part, $student);

        $this->assertSame(1, $result['completedByChapter'][(string) $chapter->id]);
        $this->assertSame(2, $result['chapters']->first()->sections_total_count);
    }
}
