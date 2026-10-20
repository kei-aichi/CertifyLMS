<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;
use App\Services\AiChatTitleGenerator;
use Illuminate\Support\Facades\Log;

final class AutoTitleAction
{
    public function __construct(private readonly AiChatTitleGenerator $generator) {}

    public function __invoke(string $conversationId, string $question, string $answer, string $assistantMessageId): void
    {
        $conversation = AiChatConversation::query()->find($conversationId);
        if ($conversation === null || ! $conversation->auto_title_enabled || $conversation->title_manually_edited_at !== null || $conversation->title !== '新しい相談') {
            return;
        }

        try {
            $title = $this->generator->generate($question, $answer);
            if ($title === null) {
                return;
            }

            AiChatConversation::query()
                ->whereKey($conversationId)
                ->where('auto_title_enabled', true)
                ->whereNull('title_manually_edited_at')
                ->where('title', '新しい相談')
                ->update(['title' => $title]);
        } catch (\Throwable $exception) {
            Log::warning('AI chat title generation failed.', [
                'conversation_id' => $conversationId,
                'assistant_message_id' => $assistantMessageId,
                'exception' => $exception::class,
                'error_code' => 'title_generation_failed',
            ]);
        }
    }
}
