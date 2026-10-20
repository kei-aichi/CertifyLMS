<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiChatConversation> */
class AiChatConversationFactory extends Factory
{
    protected $model = AiChatConversation::class;

    public function definition(): array
    {
        return ['user_id' => User::factory()->student()->inProgress(), 'enrollment_id' => null, 'section_id' => null, 'source' => 'full-screen', 'title' => '新しい相談', 'auto_title_enabled' => true, 'title_manually_edited_at' => null, 'last_message_at' => null];
    }
}
