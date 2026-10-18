<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChatMessage;

use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatPendingException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\User;
use App\UseCases\AiChatMessage\ReserveAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_it_reserves_a_message_and_increments_daily_usage(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);

        $message = app(ReserveAction::class)($user, $conversation, '質問');

        $this->assertSame(AiChatMessageStatus::Pending, $message->status);
        $this->assertDatabaseHas('ai_chat_messages', ['id' => $message->id, 'content' => '質問']);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'send_count' => 1]);
    }

    public function test_it_rejects_the_21st_send_without_mutation(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        AiChatDailyUsage::factory()->for($user)->create(['send_count' => 20]);

        $this->expectException(AiChatDailyLimitExceededException::class);
        try {
            app(ReserveAction::class)($user, $conversation, '質問');
        } finally {
            $this->assertDatabaseCount('ai_chat_messages', 0);
            $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'send_count' => 20]);
        }
    }

    public function test_it_allows_the_20th_send(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        AiChatDailyUsage::factory()->for($user)->create(['send_count' => 19]);

        app(ReserveAction::class)($user, $conversation, '質問');

        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'send_count' => 20]);
    }

    public function test_it_uses_the_application_timezone_for_the_usage_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:30:00', 'UTC'));
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);

        app(ReserveAction::class)($user, $conversation, '質問');

        $this->assertDatabaseHas('ai_chat_daily_usages', [
            'user_id' => $user->id,
            'usage_date' => '2026-10-19',
        ]);
        Carbon::setTestNow();
    }

    public function test_jst_midnight_starts_a_new_daily_usage(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-18 14:59:00', 'UTC'));
        $first = app(ReserveAction::class)($user, $conversation, '前日');
        $first->update(['status' => AiChatMessageStatus::Completed]);

        Carbon::setTestNow(Carbon::parse('2026-10-18 15:00:00', 'UTC'));
        $second = app(ReserveAction::class)($user, $conversation, '当日');

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'usage_date' => '2026-10-18', 'send_count' => 1]);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'usage_date' => '2026-10-19', 'send_count' => 1]);
        Carbon::setTestNow();
    }

    public function test_it_rejects_when_a_pending_message_exists_without_mutation(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        AiChatMessage::factory()->pending()->create(['conversation_id' => $conversation->id]);

        $this->expectException(AiChatPendingException::class);
        try {
            app(ReserveAction::class)($user, $conversation, '新しい質問');
        } finally {
            $this->assertDatabaseCount('ai_chat_messages', 1);
            $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        }
    }

    public function test_missing_api_key_does_not_create_usage_or_message(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);

        $this->expectException(GeminiApiKeyMissingException::class);
        try {
            app(ReserveAction::class)($user, $conversation, '質問');
        } finally {
            $this->assertDatabaseCount('ai_chat_messages', 0);
            $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        }
    }

    public function test_message_failure_rolls_back_usage_and_message(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id]);
        AiChatMessage::creating(static function (): void {
            throw new \RuntimeException('injected persistence failure');
        });

        $this->expectException(\RuntimeException::class);
        try {
            app(ReserveAction::class)($user, $conversation, '質問');
        } finally {
            $this->assertDatabaseCount('ai_chat_messages', 0);
            $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        }
    }
}
