<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiChatMessage> */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    public function definition(): array
    {
        return ['conversation_id' => AiChatConversation::factory(), 'role' => AiChatMessageRole::User->value, 'status' => AiChatMessageStatus::Completed->value, 'content' => fake()->sentence(), 'error_detail' => null, 'model' => null, 'input_tokens' => null, 'output_tokens' => null, 'response_time_ms' => null];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => AiChatMessageRole::Assistant->value]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => AiChatMessageStatus::Pending->value]);
    }

    public function error(): static
    {
        return $this->state(fn () => ['status' => AiChatMessageStatus::Error->value]);
    }
}
