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

    public function test_student_json_excludes_internal_message_and_conversation_metadata(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $conversation = AiChatConversation::factory()->for($user)->create([
            'auto_title_enabled' => false,
            'title_manually_edited_at' => now(),
        ]);
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create([
            'role' => 'assistant',
            'status' => 'completed',
            'content' => '回答',
            'model' => 'gemini-2.5-flash',
            'input_tokens' => 12,
            'output_tokens' => 8,
            'response_time_ms' => 321,
            'error_detail' => null,
        ]);

        $response = $this->actingAs($user)->getJson(route('ai-chat.conversations.show', $conversation));

        $response->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonMissingPath('conversation.auto_title_enabled')
            ->assertJsonMissingPath('conversation.title_manually_edited_at')
            ->assertJsonMissingPath('messages.0.model')
            ->assertJsonMissingPath('messages.0.input_tokens')
            ->assertJsonMissingPath('messages.0.output_tokens')
            ->assertJsonMissingPath('messages.0.response_time_ms')
            ->assertJsonMissingPath('messages.0.error_detail');
    }

    public function test_all_json_endpoints_expose_only_the_student_contract(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'modelVersion' => 'gemini-2.5-flash',
            'candidates' => [['content' => ['parts' => [['text' => '回答']]], 'finishReason' => 'STOP']],
        ], 200)]);
        $user = User::factory()->student()->inProgress()->create();

        $created = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget']);
        $created->assertCreated();
        $this->assertSame(['conversation'], array_keys($created->json()));
        $this->assertSame(['id', 'title', 'section_id', 'created_at', 'last_message_at'], array_keys($created->json('conversation')));
        $conversation = AiChatConversation::firstOrFail();

        $shown = $this->actingAs($user)->getJson(route('ai-chat.conversations.show', $conversation));
        $shown->assertOk();
        $this->assertSame(['conversation', 'messages'], array_keys($shown->json()));
        $this->assertSame(['id', 'title', 'section_id', 'created_at', 'last_message_at'], array_keys($shown->json('conversation')));

        $updated = $this->actingAs($user)->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '更新後']);
        $updated->assertOk();
        $this->assertSame(['conversation'], array_keys($updated->json()));
        $this->assertSame(['id', 'title', 'section_id', 'created_at', 'last_message_at'], array_keys($updated->json('conversation')));

        $sent = $this->actingAs($user)->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問']);
        $sent->assertOk();
        $this->assertSame(['user_message', 'assistant_message', 'conversation'], array_keys($sent->json()));
        $this->assertSame(['id', 'role', 'status', 'content', 'created_at'], array_keys($sent->json('user_message')));
        $this->assertSame(['id', 'role', 'status', 'content', 'created_at'], array_keys($sent->json('assistant_message')));
        $this->assertSame(['id', 'title', 'section_id', 'created_at', 'last_message_at'], array_keys($sent->json('conversation')));
    }

    public function test_student_chat_blade_hides_operational_metadata(): void
    {
        config(['ai-chat.enabled' => true]);
        $user = $this->student();
        $conversation = AiChatConversation::factory()->for($user)->create(['title' => '表示タイトル']);
        AiChatMessage::factory()->for($conversation, 'conversation')->create([
            'role' => 'assistant',
            'status' => 'completed',
            'content' => '表示本文',
            'model' => 'gemini-2.5-flash',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'response_time_ms' => 500,
            'error_detail' => 'internal_detail',
        ]);

        $response = $this->actingAs($user)->get(route('ai-chat.conversations.show', $conversation));

        $response->assertOk()
            ->assertSee('表示タイトル')
            ->assertSee('表示本文')
            ->assertDontSee('gemini-2.5-flash')
            ->assertDontSee('internal_detail')
            ->assertDontSee('tokens')
            ->assertDontSee('0.5 s');
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

    public function test_html_initial_message_without_api_key_returns_with_safe_flash_message(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => null]);
        $user = $this->student();

        $response = $this->actingAs($user)->from(route('ai-chat.index'))->post(route('ai-chat.conversations.store'), [
            'source' => 'full-screen', 'message' => '再入力する質問',
        ]);

        $response->assertRedirect(route('ai-chat.index'))
            ->assertSessionHas('error', 'AIチャットは現在利用できません。時間をおいて再度お試しください。')
            ->assertSessionHasInput('message', '再入力する質問');
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
