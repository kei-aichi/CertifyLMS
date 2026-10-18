<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationCrudTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student->value]);
    }

    private function publishedSection(Certification $certification): Section
    {
        $certification->update(['status' => CertificationStatus::Published->value]);
        $part = Part::factory()->forCertification($certification)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();

        return Section::factory()->forChapter($chapter)->published()->create();
    }

    public function test_student_can_create_show_update_and_delete_own_conversation(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $response = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget']);
        $response->assertCreated();
        $conversation = AiChatConversation::firstOrFail();
        $this->actingAs($user)->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '手動タイトル'])->assertOk();
        $this->assertNotNull($conversation->refresh()->title_manually_edited_at);
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create();
        AiChatDailyUsage::factory()->for($user)->create();
        $this->actingAs($user)->deleteJson(route('ai-chat.conversations.destroy', $conversation))->assertNoContent();
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_chat_messages', ['id' => $message->id]);
        $this->assertDatabaseCount('ai_chat_daily_usages', 1);
    }

    public function test_section_conversation_is_reused_unless_force_new(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $cert = Certification::factory()->create();
        $section = $this->publishedSection($cert);
        $enrollment = Enrollment::factory()->for($user)->for($cert)->state(['status' => EnrollmentStatus::Learning->value])->create();
        $first = AiChatConversation::factory()->create([
            'user_id' => $user->id,
            'section_id' => $section->id,
            'enrollment_id' => $enrollment->id,
            'source' => 'widget',
            'last_message_at' => now(),
        ]);
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $first->id, 'user_id' => $user->id, 'section_id' => $section->id]);
        $response = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id]);
        $response->assertOk()->assertJsonPath('conversation.id', $first->id);
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'force_new' => true])->assertCreated();
        $this->assertDatabaseCount('ai_chat_conversations', 2);
    }

    public function test_initial_message_without_api_key_returns_503_without_persisting_anything(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget', 'message' => '質問',
        ])->assertStatus(503);
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
    }

    public function test_draft_section_is_rejected(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $certification = Certification::factory()->published()->create();
        $section = Section::factory()->create();
        $enrollment = Enrollment::factory()->for($user)->for($certification)->learning()->create();
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id,
        ])->assertUnprocessable();
    }

    public function test_unpublished_parents_and_archived_certification_are_rejected(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        foreach (['chapter', 'part', 'certification'] as $level) {
            $cert = Certification::factory()->published()->create();
            $section = $this->publishedSection($cert);
            $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
            if ($level === 'chapter') {
                $section->chapter->update(['status' => ContentStatus::Draft->value]);
            }
            if ($level === 'part') {
                $section->chapter->part->update(['status' => ContentStatus::Draft->value]);
            }
            if ($level === 'certification') {
                $cert->update(['status' => CertificationStatus::Draft->value]);
            }
            $response = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id]);
            $this->assertContains($response->status(), [404, 422]);
        }
        $cert = Certification::factory()->archived()->create();
        $part = Part::factory()->forCertification($cert)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $section = Section::factory()->forChapter($chapter)->published()->create();
        $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
        $response = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id]);
        $this->assertContains($response->status(), [404, 422]);
    }

    public function test_mismatched_enrollment_and_other_users_are_not_reused(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $other = $this->student();
        $cert = Certification::factory()->published()->create();
        $section = $this->publishedSection($cert);
        $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
        $otherConversation = AiChatConversation::factory()->create(['user_id' => $other->id, 'section_id' => $section->id]);
        $otherEnrollment = Enrollment::factory()->for($other)->for($cert)->learning()->create();
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id])->assertCreated()->assertJsonMissing(['id' => $otherConversation->id]);
        $otherCert = Certification::factory()->published()->create();
        $this->assertNotSame($otherCert->id, $enrollment->certification_id);
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $otherEnrollment->id])->assertUnprocessable();
    }

    public function test_latest_candidate_is_reused(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $cert = Certification::factory()->published()->create();
        $section = $this->publishedSection($cert);
        $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
        AiChatConversation::factory()->create(['user_id' => $user->id, 'section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'source' => 'widget', 'last_message_at' => now()->subDay()]);
        $latest = AiChatConversation::factory()->create(['user_id' => $user->id, 'section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'source' => 'widget', 'last_message_at' => now()]);
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id])->assertOk()->assertJsonPath('conversation.id', $latest->id);
    }

    public function test_initial_message_stale_recovery_is_kept_when_daily_limit_returns_429(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
        Http::fake();
        $user = $this->student();
        $cert = Certification::factory()->create();
        $section = $this->publishedSection($cert);
        $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $user->id,
            'section_id' => $section->id,
            'enrollment_id' => $enrollment->id,
            'source' => 'widget',
        ]);
        $pending = AiChatMessage::factory()->pending()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
        ]);
        AiChatMessage::query()->whereKey($pending->id)->update(['updated_at' => now()->subMinutes(10)]);
        AiChatDailyUsage::factory()->for($user)->create(['send_count' => 20]);

        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
            'enrollment_id' => $enrollment->id,
            'message' => '新しい質問',
        ])->assertStatus(429);

        $this->assertDatabaseHas('ai_chat_messages', [
            'id' => $pending->id,
            'status' => 'completed',
            'error_detail' => 'stale_pending',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'status' => 'error',
            'error_detail' => 'stale_pending',
        ]);
        $this->assertDatabaseMissing('ai_chat_messages', ['content' => '新しい質問']);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $user->id, 'send_count' => 20]);
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        Http::assertNothingSent();
    }

    public function test_initial_message_active_pending_returns_409_without_mutation(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
        Http::fake();
        $user = $this->student();
        $cert = Certification::factory()->create();
        $section = $this->publishedSection($cert);
        $enrollment = Enrollment::factory()->for($user)->for($cert)->learning()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id, 'section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'source' => 'widget']);
        $pending = AiChatMessage::factory()->pending()->create(['conversation_id' => $conversation->id, 'role' => 'user']);

        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'message' => '新しい質問'])->assertStatus(409);

        $this->assertDatabaseHas('ai_chat_messages', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('ai_chat_messages', ['content' => '新しい質問']);
        Http::assertNothingSent();
    }
}
