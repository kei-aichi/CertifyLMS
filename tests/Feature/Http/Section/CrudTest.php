<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Section;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionImage;
use App\Models\SectionProgress;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionAnswer;
use App\Models\SectionQuestionAttempt;
use App\Models\SectionQuestionOption;
use App\Models\AiChatConversation;
use App\Models\LearningSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_store_section_includes_body(): void
    {
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($cert)->draft()->create();
        $chapter = Chapter::factory()->forPart($part)->draft()->create();

        $this->actingAs($admin)
            ->post(route('admin.chapters.sections.store', $chapter), [
                'title' => 'はじめに',
                'body' => '## 概要

本セクションでは...',
            ])
            ->assertRedirect();

        $section = Section::where('title', 'はじめに')->firstOrFail();
        $this->assertStringContainsString('概要', $section->body);
        $this->assertSame('draft', $section->status->value);
    }

    public function test_update_updates_body(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->patch(route('admin.sections.update', $section), [
                'title' => '新タイトル',
                'body' => 'updated body',
            ])
            ->assertRedirect();

        $section->refresh();
        $this->assertSame('新タイトル', $section->title);
        $this->assertSame('updated body', $section->body);
    }

    public function test_preview_returns_html(): void
    {
        $admin = User::factory()->admin()->create();
        [$part, $chapter, $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->postJson(route('admin.sections.preview', $section), [
                'body' => "# タイトル\n\n本文",
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    public function test_admin_can_delete_draft_section_without_related_data(): void
    {
        $admin = User::factory()->admin()->create();
        [, , $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        $this->actingAs($admin)
            ->delete(route('admin.sections.destroy', $section))
            ->assertRedirect(route('admin.chapters.show', $section->chapter_id));

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_cannot_delete_section_with_images(): void
    {
        [$section, $admin] = $this->sectionWithRelatedData();
        $image = SectionImage::factory()->forSection($section)->create();

        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('section_images', ['id' => $image->id]);
    }

    public function test_cannot_delete_section_with_unanswered_question_and_keeps_options(): void
    {
        [$section, $admin] = $this->sectionWithRelatedData();
        $question = SectionQuestion::factory()->forSection($section)->withOptions()->create();
        $optionIds = $question->options()->pluck('id')->all();

        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('section_questions', ['id' => $question->id]);
        foreach ($optionIds as $optionId) {
            $this->assertDatabaseHas('section_question_options', ['id' => $optionId]);
        }
    }

    public function test_cannot_delete_section_with_learning_session_or_progress(): void
    {
        [$section, $admin] = $this->sectionWithRelatedData();
        $session = LearningSession::factory()->forSection($section)->create();

        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('learning_sessions', ['id' => $session->id]);

        $progress = SectionProgress::factory()->forSection($section)->create();
        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('section_progresses', ['id' => $progress->id]);
    }

    public function test_cannot_delete_section_with_answer_or_attempt_history(): void
    {
        [$section, $admin] = $this->sectionWithRelatedData();
        $question = SectionQuestion::factory()->forSection($section)->create();
        $answer = SectionQuestionAnswer::factory()->forQuestion($question)->create();

        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('section_question_answers', ['id' => $answer->id]);

        $attempt = SectionQuestionAttempt::factory()->forQuestion($question)->create();
        $this->assertSectionDeletionRejected($admin, $section);
        $this->assertDatabaseHas('section_question_attempts', ['id' => $attempt->id]);
    }

    public function test_section_with_only_ai_chat_conversation_can_be_deleted_and_conversation_is_preserved(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        [, , $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');
        $conversation = AiChatConversation::factory()->for($student)->create(['section_id' => $section->id]);

        $this->actingAs($admin)
            ->delete(route('admin.sections.destroy', $section))
            ->assertRedirect(route('admin.chapters.show', $section->chapter_id));

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id, 'section_id' => null]);
    }

    public function test_cannot_delete_published_section(): void
    {
        $admin = User::factory()->admin()->create();
        [, , $section] = $this->makePartChain(Certification::factory()->published()->create(), 'published');

        $this->actingAs($admin)
            ->deleteJson(route('admin.sections.destroy', $section))
            ->assertStatus(409);

        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_guest_student_and_unassigned_coach_cannot_delete_section(): void
    {
        $section = $this->makePartChain(Certification::factory()->published()->create(), 'draft')[2];

        $this->delete(route('admin.sections.destroy', $section))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())
            ->deleteJson(route('admin.sections.destroy', $section))
            ->assertForbidden();
        $this->actingAs(User::factory()->coach()->create())
            ->deleteJson(route('admin.sections.destroy', $section))
            ->assertForbidden();

        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    /** @return array{Section, User} */
    private function sectionWithRelatedData(): array
    {
        $admin = User::factory()->admin()->create();
        [, , $section] = $this->makePartChain(Certification::factory()->published()->create(), 'draft');

        return [$section, $admin];
    }

    private function assertSectionDeletionRejected(User $admin, Section $section): void
    {
        $this->actingAs($admin)
            ->deleteJson(route('admin.sections.destroy', $section))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Sectionに子データまたは関連データが存在するため削除できません。']);

        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }
}
