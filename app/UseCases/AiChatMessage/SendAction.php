<?php

declare(strict_types=1);

namespace App\UseCases\AiChatMessage;

use App\DTOs\AiChat\GeminiChatResult;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChatPromptBuilder;
use App\Services\GeminiChatService;
use App\UseCases\AiChatConversation\AutoTitleAction;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SendAction
{
    public function __construct(
        private readonly ReserveAction $reserve,
        private readonly AiChatPromptBuilder $promptBuilder,
        private readonly GeminiChatService $gemini,
    ) {}

    public function __invoke(User $user, AiChatConversation $conversation, string $content): ?AiChatMessage
    {
        $message = ($this->reserve)($user, $conversation, $content);

        return $this->sendReserved($user, $conversation, $message);
    }

    public function sendReserved(User $user, AiChatConversation $conversation, AiChatMessage $message): ?AiChatMessage
    {

        try {
            $prompt = $this->promptBuilder->build($conversation->fresh(), $message->fresh());
            $result = $this->gemini->generate($prompt);
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof GeminiApiException ? 'gemini_api_error' : 'internal_error';
            try {
                $this->saveFailure($user, $conversation, $message, $errorCode);
            } catch (\Throwable $saveException) {
                Log::error('AI chat error state could not be persisted.', [
                    'conversation_id' => $conversation->getKey(),
                    'message_id' => $message->getKey(),
                    'exception' => $saveException::class,
                ]);
            }
            Log::error('AI chat processing failed.', [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
                'exception' => $exception::class,
            ]);
            if (! $exception instanceof GeminiApiException) {
                throw $exception;
            }
            throw $exception;
        }

        $assistant = $this->saveSuccess($user, $conversation, $message, $result);
        if ($assistant !== null) {
            $this->scheduleAutoTitle($conversation, $message, $assistant);
        }

        return $assistant;
    }

    private function scheduleAutoTitle(AiChatConversation $conversation, AiChatMessage $question, AiChatMessage $assistant): void
    {
        $conversation = $conversation->fresh();
        if ($conversation === null || ! $conversation->auto_title_enabled || $conversation->title_manually_edited_at !== null || $conversation->title !== '新しい相談') {
            return;
        }
        if ($conversation->messages()->where('role', AiChatMessageRole::Assistant->value)->where('status', AiChatMessageStatus::Completed->value)->count() !== 1) {
            return;
        }

        app(Dispatcher::class)->dispatchAfterResponse(function () use ($conversation, $question, $assistant): void {
            app(AutoTitleAction::class)($conversation->getKey(), $question->content, $assistant->content, $assistant->getKey());
        });
    }

    private function saveSuccess(User $user, AiChatConversation $conversation, AiChatMessage $message, GeminiChatResult $result): ?AiChatMessage
    {
        return DB::transaction(function () use ($user, $conversation, $message, $result): ?AiChatMessage {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $lockedConversation = AiChatConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $updated = AiChatMessage::query()
                ->whereKey($message->getKey())
                ->where('status', AiChatMessageStatus::Pending->value)
                ->update(['status' => AiChatMessageStatus::Completed->value, 'updated_at' => now()]);
            if ($updated !== 1) {
                return null;
            }

            $assistant = $lockedConversation->messages()->create([
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Completed,
                'content' => $result->text,
                'model' => $result->model,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'response_time_ms' => $result->responseTimeMs,
            ]);
            $lockedConversation->update(['last_message_at' => now()]);

            return $assistant;
        });
    }

    private function saveFailure(User $user, AiChatConversation $conversation, AiChatMessage $message, string $errorCode): ?AiChatMessage
    {
        return DB::transaction(function () use ($user, $conversation, $message, $errorCode): ?AiChatMessage {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $lockedConversation = AiChatConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $updated = AiChatMessage::query()
                ->whereKey($message->getKey())
                ->where('status', AiChatMessageStatus::Pending->value)
                ->update(['status' => AiChatMessageStatus::Completed->value, 'updated_at' => now()]);
            if ($updated !== 1) {
                return null;
            }

            $assistant = $lockedConversation->messages()->create([
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Error,
                'content' => 'AI応答を取得できませんでした。時間をおいて再度お試しください。',
                'error_detail' => $errorCode,
            ]);
            $lockedConversation->update(['last_message_at' => now()]);

            return $assistant;
        });
    }
}
