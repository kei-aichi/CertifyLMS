<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('ai-chat.index'))->assertRedirect(route('login'));
    }

    public function test_coach_and_admin_are_forbidden(): void
    {
        foreach ([User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('ai-chat.index'))->assertForbidden();
        }
    }

    public function test_in_progress_student_reaches_controller_boundary(): void
    {
        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->get(route('ai-chat.index'))
            ->assertStatus(501);
    }

    public function test_feature_disabled_returns_not_found(): void
    {
        config(['ai-chat.enabled' => false]);

        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->get(route('ai-chat.index'))
            ->assertNotFound();
    }

    public function test_other_users_conversation_is_forbidden(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_other_users_conversation_is_forbidden_for_update_delete_and_message(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->for($owner)->create(['title' => '秘密の会話']);

        $this->actingAs($other)->patch(route('ai-chat.conversations.update', $conversation), ['title' => '変更'])
            ->assertForbidden()->assertDontSee('秘密の会話');
        $this->actingAs($other)->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden()->assertDontSee('秘密の会話');
        $this->actingAs($other)->post(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])
            ->assertForbidden()->assertDontSee('秘密の会話');
    }

    public function test_graduated_withdrawn_and_invited_students_are_forbidden(): void
    {
        foreach ([User::factory()->graduated(), User::factory()->withdrawn(), User::factory()->invited()] as $factory) {
            $this->actingAs($factory->create())->get(route('ai-chat.index'))->assertForbidden();
        }
    }

    public function test_route_names_and_methods_are_registered(): void
    {
        self::assertSame('GET', app('router')->getRoutes()->getByName('ai-chat.index')->methods()[0]);
        self::assertSame('POST', app('router')->getRoutes()->getByName('ai-chat.conversations.store')->methods()[0]);
        self::assertSame('GET', app('router')->getRoutes()->getByName('ai-chat.conversations.show')->methods()[0]);
        self::assertSame('PATCH', app('router')->getRoutes()->getByName('ai-chat.conversations.update')->methods()[0]);
        self::assertSame('DELETE', app('router')->getRoutes()->getByName('ai-chat.conversations.destroy')->methods()[0]);
        self::assertSame('POST', app('router')->getRoutes()->getByName('ai-chat.conversations.messages.store')->methods()[0]);
    }

    public function test_conversation_and_message_input_limits_are_validated(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('ai-chat.conversations.store'), [
                'source' => 'invalid',
                'message' => str_repeat('a', 2001),
                'force_new' => 'not-a-boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'message', 'force_new']);

        $this->actingAs($user)
            ->patch(route('ai-chat.conversations.update', ['conversation' => '01invalid']), [
                'title' => str_repeat('a', 101),
            ])
            ->assertNotFound();

        $conversation = AiChatConversation::factory()->for($user)->create();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('ai-chat.conversations.messages.store', $conversation), [
                'content' => str_repeat('a', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_store_conversation_rejects_foreign_enrollment_and_unavailable_section(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $foreignEnrollment = Enrollment::factory()->for($other)->create();
        $section = Section::factory()->create();

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->postJson(route('ai-chat.conversations.store'), [
                'source' => 'widget', 'enrollment_id' => $foreignEnrollment->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->postJson(route('ai-chat.conversations.store'), [
                'source' => 'widget', 'section_id' => $section->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('section_id');
    }

    public function test_store_conversation_rejects_section_and_enrollment_from_different_certifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $firstCertification = Certification::factory()->published()->create();
        $secondCertification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($user)->for($firstCertification)->create();
        $secondEnrollment = Enrollment::factory()->for($user)->for($secondCertification)->create();
        $part = Part::factory()->forCertification($secondCertification)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $section = Section::factory()->forChapter($chapter)->published()->create();

        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget', 'section_id' => $section->id, 'enrollment_id' => $enrollment->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('section_id');

        self::assertNotSame($enrollment->certification_id, $secondEnrollment->certification_id);
    }
}
