<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChatMessage;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\User;
use App\UseCases\AiChatMessage\SendAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_success_completes_user_and_saves_assistant(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'modelVersion' => 'gemini-2.5-flash',
            'candidates' => [['content' => ['parts' => [['text' => '回答']]], 'finishReason' => 'STOP']],
        ], 200)]);

        $assistant = app(SendAction::class)($user, $conversation, '質問');

        $this->assertSame(AiChatMessageRole::Assistant, $assistant->role);
        $this->assertSame(AiChatMessageStatus::Completed, $assistant->status);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'status' => 'completed', 'content' => '質問']);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'assistant', 'status' => 'completed', 'content' => '回答']);
        $this->assertNotNull($conversation->refresh()->last_message_at);
    }

    public function test_api_failure_completes_user_and_saves_safe_error_then_rethrows(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'upstream detail']], 503)]);

        $this->expectException(GeminiApiException::class);
        try {
            app(SendAction::class)($user, $conversation, '質問');
        } finally {
            $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'status' => 'completed']);
            $this->assertDatabaseHas('ai_chat_messages', ['role' => 'assistant', 'status' => 'error', 'error_detail' => 'gemini_api_error']);
            $this->assertDatabaseMissing('ai_chat_messages', ['content' => 'upstream detail']);
        }
    }
}
