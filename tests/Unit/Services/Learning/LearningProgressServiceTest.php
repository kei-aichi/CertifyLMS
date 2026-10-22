<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Learning;

use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\LearningProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_counts_only_published_content_and_completed_progress(): void
    {
        [$enrollment, $part, $chapter] = $this->scenario();
        $publishedA = Section::factory()->for($chapter)->published()->create();
        $publishedB = Section::factory()->for($chapter)->published()->create();
        Section::factory()->for($chapter)->draft()->create();
        $draftPart = Part::factory()->for($enrollment->certification)->draft()->create();
        $draftChapter = Chapter::factory()->for($draftPart)->published()->create();
        Section::factory()->for($draftChapter)->published()->create();

        SectionProgress::factory()->forEnrollment($enrollment)->forSection($publishedA)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(2, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.5, $summary->sectionCompletionRatio);
        $this->assertSame(0.5, $summary->overallCompletionRatio);
    }

    public function test_summarize_counts_chapter_and_part_when_all_sections_are_complete(): void
    {
        [$enrollment, $part, $chapter] = $this->scenario();
        $sectionA = Section::factory()->for($chapter)->published()->create();
        $sectionB = Section::factory()->for($chapter)->published()->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sectionA)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sectionB)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(2, $summary->sectionsCompleted);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(1, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->chapterCompletionRatio);
        $this->assertSame(1.0, $summary->partCompletionRatio);
    }

    public function test_summarize_returns_zero_ratios_for_empty_content(): void
    {
        [$enrollment] = $this->scenario();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);
        $this->assertSame(0.0, $summary->partCompletionRatio);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_rounds_ratios_to_four_decimal_places(): void
    {
        [$enrollment, , $chapter] = $this->scenario();
        $sections = Section::factory()->for($chapter)->published()->count(6)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sections[0])->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(0.1667, $summary->sectionCompletionRatio);
        $this->assertSame($summary->sectionCompletionRatio, $summary->overallCompletionRatio);
    }

    public function test_section_ratios_keep_enrollments_separate_and_initialize_zeroes(): void
    {
        [$enrollmentA, , $chapterA] = $this->scenario();
        [$enrollmentB, , $chapterB] = $this->scenario();
        $sectionA = Section::factory()->for($chapterA)->published()->create();
        Section::factory()->for($chapterB)->published()->create();
        SectionProgress::factory()->forEnrollment($enrollmentA)->forSection($sectionA)->create();

        $result = app(LearningProgressService::class)->sectionRatios(new Collection([$enrollmentA, $enrollmentB]));

        $this->assertSame(1.0, $result[$enrollmentA->id]);
        $this->assertSame(0.0, $result[$enrollmentB->id]);
    }

    public function test_section_ratios_return_empty_for_empty_collection(): void
    {
        $result = app(LearningProgressService::class)->sectionRatios(new Collection);

        $this->assertSame([], $result);
    }

    public function test_chapter_section_progress_returns_totals_and_done_counts(): void
    {
        [$enrollment, $part, $chapter] = $this->scenario();
        $done = Section::factory()->for($chapter)->published()->create();
        Section::factory()->for($chapter)->published()->create();
        Section::factory()->for($chapter)->draft()->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($done)->create();

        $result = app(LearningProgressService::class)->chapterSectionProgress($part, $enrollment);

        $this->assertSame(['total' => 2, 'done' => 1], $result[(string) $chapter->id]);
    }

    public function test_chapter_section_progress_returns_zero_done_without_enrollment(): void
    {
        [, $part, $chapter] = $this->scenario();
        Section::factory()->for($chapter)->published()->count(2)->create();

        $result = app(LearningProgressService::class)->chapterSectionProgress($part, null);

        $this->assertSame(['total' => 2, 'done' => 0], $result[(string) $chapter->id]);
    }

    /** @return array{0: Enrollment, 1: Part, 2: Chapter} */
    private function scenario(): array
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->state(['status' => EnrollmentStatus::Learning->value])
            ->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();

        return [$enrollment, $part, $chapter];
    }
}
