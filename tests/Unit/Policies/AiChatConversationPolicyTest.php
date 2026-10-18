<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_in_progress_student_owner_can_operate_a_conversation(): void
    {
        config(['ai-chat.enabled' => true]);
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->for($owner)->create();
        $policy = new AiChatConversationPolicy;

        self::assertTrue($policy->view($owner, $conversation));
        self::assertTrue($policy->sendMessage($owner, $conversation));
        self::assertFalse($policy->view($other, $conversation));
    }
}
