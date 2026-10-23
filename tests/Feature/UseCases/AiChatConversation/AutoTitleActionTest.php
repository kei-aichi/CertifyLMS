<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChatConversation;

use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\UseCases\AiChatConversation\AutoTitleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class AutoTitleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_only_an_unedited_initial_conversation(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '「Network Basics」']]], 'finishReason' => 'STOP']]], 200)]);
        $conversation = AiChatConversation::factory()->create();
        $assistant = AiChatMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'status' => AiChatMessageStatus::Completed]);

        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);

        $this->assertSame('Network Basics', $conversation->refresh()->title);
        Http::assertSentCount(1);
    }

    public function test_it_does_not_update_a_manually_edited_title(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake();
        $conversation = AiChatConversation::factory()->create(['title' => '手動タイトル', 'auto_title_enabled' => false, 'title_manually_edited_at' => now()]);

        app(AutoTitleAction::class)($conversation->id, '質問', '回答', 'assistant-id');

        $this->assertSame('手動タイトル', $conversation->refresh()->title);
        Http::assertNothingSent();
    }

    public function test_it_truncates_titles_to_100_characters(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => str_repeat('A', 120)]]]]]], 200)]);
        $conversation = AiChatConversation::factory()->create();
        $assistant = AiChatMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'status' => AiChatMessageStatus::Completed]);

        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);

        $this->assertSame(100, mb_strlen($conversation->refresh()->title, 'UTF-8'));
    }

    public function test_it_keeps_the_initial_title_for_blank_or_failed_generation(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        $conversation = AiChatConversation::factory()->create();
        $assistant = AiChatMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'status' => AiChatMessageStatus::Completed]);

        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => " \n  "]]]]]], 200)]);
        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);
        $this->assertSame('新しい相談', $conversation->refresh()->title);

        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([], 503)]);
        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);
        $this->assertSame('新しい相談', $conversation->refresh()->title);
        $this->assertDatabaseHas('ai_chat_messages', ['id' => $assistant->id, 'status' => 'completed']);
    }

    public function test_auto_title_disabled_does_not_call_gemini(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake();
        $conversation = AiChatConversation::factory()->create(['auto_title_enabled' => false]);
        $assistant = AiChatMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'status' => AiChatMessageStatus::Completed]);

        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);

        Http::assertNothingSent();
    }

    public function test_manual_edit_during_generation_cannot_be_overwritten(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '自動タイトル']]]]]], 200)]);
        $conversation = AiChatConversation::factory()->create();
        $assistant = AiChatMessage::factory()->assistant()->create(['conversation_id' => $conversation->id, 'status' => AiChatMessageStatus::Completed]);

        $conversation->update(['title' => '手動タイトル', 'auto_title_enabled' => false, 'title_manually_edited_at' => now()]);
        app(AutoTitleAction::class)($conversation->id, '質問', '回答', $assistant->id);

        $this->assertSame('手動タイトル', $conversation->refresh()->title);
    }
}
