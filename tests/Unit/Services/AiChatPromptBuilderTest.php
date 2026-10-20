<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Services\AiChatPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_context_history_roles_and_excludes_current_and_error_pair(): void
    {
        $conversation = AiChatConversation::factory()->create();
        $failedQuestion = AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => 'failed']);
        AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Error, 'content' => 'error']);
        AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Completed, 'content' => 'answer']);
        $current = AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Pending, 'content' => 'current']);
        $prompt = app(AiChatPromptBuilder::class)->build($conversation, $current);
        $this->assertSame('current', data_get($prompt->contents, '1.parts.0.text'));
        $this->assertStringNotContainsString($failedQuestion->content, json_encode($prompt->contents));
        $this->assertStringNotContainsString('error', json_encode($prompt->contents));
        $this->assertSame('model', data_get($prompt->contents, '0.role'));
    }

    public function test_includes_section_hierarchy_and_body(): void
    {
        $certification = Certification::factory()->create(['name' => '資格A']);
        $part = Part::factory()->forCertification($certification)->create(['title' => 'Part A']);
        $chapter = Chapter::factory()->forPart($part)->create(['title' => 'Chapter A']);
        $section = Section::factory()->forChapter($chapter)->create(['title' => 'Section A', 'body' => '本文A']);
        $conversation = AiChatConversation::factory()->create(['section_id' => $section->id]);
        $current = AiChatMessage::factory()->for($conversation, 'conversation')->create(['status' => AiChatMessageStatus::Pending]);
        $prompt = app(AiChatPromptBuilder::class)->build($conversation, $current);
        $this->assertStringContainsString('資格A', $prompt->systemInstruction);
        $this->assertStringContainsString('Part A', $prompt->systemInstruction);
        $this->assertStringContainsString('Chapter A', $prompt->systemInstruction);
        $this->assertStringContainsString('Section A', $prompt->systemInstruction);
        $this->assertStringContainsString('本文A', $prompt->systemInstruction);
    }

    public function test_limits_history_after_exclusions_and_preserves_order(): void
    {
        $conversation = AiChatConversation::factory()->create();
        for ($i = 0; $i < 22; $i++) {
            AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => "q{$i}", 'created_at' => now()->subMinutes(30 - $i)]);
        }
        $current = AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Pending, 'content' => 'current']);
        $prompt = app(AiChatPromptBuilder::class)->build($conversation, $current);
        $this->assertCount(21, $prompt->contents);
        $this->assertSame('q2', data_get($prompt->contents, '0.parts.0.text'));
        $this->assertSame('current', data_get($prompt->contents, '20.parts.0.text'));
    }

    public function test_does_not_exclude_non_user_message_before_error(): void
    {
        $conversation = AiChatConversation::factory()->create();
        AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Completed, 'content' => 'keep']);
        AiChatMessage::factory()->for($conversation, 'conversation')->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Error, 'content' => 'error']);
        $current = AiChatMessage::factory()->for($conversation, 'conversation')->create(['status' => AiChatMessageStatus::Pending, 'content' => 'current']);
        $prompt = app(AiChatPromptBuilder::class)->build($conversation, $current);
        $this->assertStringContainsString('keep', json_encode($prompt->contents));
    }

    public function test_does_not_mix_another_conversation(): void
    {
        $conversation = AiChatConversation::factory()->create();
        $other = AiChatConversation::factory()->create();
        AiChatMessage::factory()->for($other, 'conversation')->create(['content' => 'other']);
        $current = AiChatMessage::factory()->for($conversation, 'conversation')->create(['status' => AiChatMessageStatus::Pending, 'content' => 'current']);
        $prompt = app(AiChatPromptBuilder::class)->build($conversation, $current);
        $this->assertStringNotContainsString('other', json_encode($prompt->contents));
    }
}
