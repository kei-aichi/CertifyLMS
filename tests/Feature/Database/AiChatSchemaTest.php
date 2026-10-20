<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_generate_ulids_cast_enums_and_define_relationships(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->for($user)->create();
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create();
        $usage = AiChatDailyUsage::factory()->for($user)->create();

        self::assertNotEmpty($conversation->id);
        self::assertNotEmpty($message->id);
        self::assertNotEmpty($usage->id);
        self::assertSame($user->id, $conversation->user->id);
        self::assertSame($conversation->id, $message->conversation->id);
        self::assertSame($user->id, $usage->user->id);
        self::assertInstanceOf(AiChatMessageRole::class, $message->role);
        self::assertInstanceOf(AiChatMessageStatus::class, $message->status);
        self::assertSame(0, $usage->send_count);
    }

    public function test_daily_usage_is_unique_per_user_and_date(): void
    {
        $user = User::factory()->create();
        AiChatDailyUsage::factory()->for($user)->create(['usage_date' => '2026-01-01']);

        $this->expectException(QueryException::class);
        AiChatDailyUsage::factory()->for($user)->create(['usage_date' => '2026-01-01']);
    }

    public function test_deleting_conversation_cascades_messages_and_keeps_daily_usage(): void
    {
        $user = User::factory()->create();
        $conversation = AiChatConversation::factory()->for($user)->create();
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create();
        $usage = AiChatDailyUsage::factory()->for($user)->create();

        $conversation->delete();

        $this->assertDatabaseMissing('ai_chat_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['id' => $usage->id]);
    }

    public function test_physical_user_deletion_is_restricted_by_ai_chat_foreign_keys(): void
    {
        $user = User::factory()->create();
        AiChatConversation::factory()->for($user)->create();

        $this->expectException(QueryException::class);
        $user->forceDelete();
    }

    public function test_deleting_enrollment_and_section_nulls_conversation_context(): void
    {
        $user = User::factory()->create();
        $enrollment = Enrollment::factory()->for($user)->create();
        $section = Section::factory()->create();
        $conversation = AiChatConversation::factory()->for($user)->create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
        ]);

        $enrollment->forceDelete();
        $section->delete();

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'enrollment_id' => null,
            'section_id' => null,
        ]);
    }
}
