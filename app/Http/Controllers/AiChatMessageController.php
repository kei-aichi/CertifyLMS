<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatPendingException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\UseCases\AiChatMessage\SendAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AiChatMessageController extends Controller
{
    public function store(StoreMessageRequest $request, AiChatConversation $conversation, SendAction $action): JsonResponse|RedirectResponse
    {
        try {
            $assistant = $action($request->user(), $conversation, $request->validated('content'));
        } catch (AiChatPendingException $exception) {
            abort(409, 'この会話は現在処理中です。');
        } catch (AiChatDailyLimitExceededException $exception) {
            abort(429, '本日のAIチャット利用上限に達しました。');
        } catch (GeminiApiKeyMissingException $exception) {
            abort(503, 'AIチャットは現在利用できません。');
        } catch (GeminiApiException $exception) {
            abort(502, $exception->upstreamStatus > 0 ? 'AIサービスでエラーが発生しました。' : 'AIサービスに接続できません。');
        }

        $conversation->refresh();
        $payload = [
            'user_message' => $this->messagePayload($conversation->messages()->where('role', 'user')->latest('created_at')->first()),
            'assistant_message' => $this->messagePayload($assistant),
            'conversation' => $this->conversationPayload($conversation),
        ];
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    private function messagePayload(?AiChatMessage $message): ?array
    {
        return $message?->only(['id', 'role', 'status', 'content', 'created_at']);
    }

    private function conversationPayload(AiChatConversation $conversation): array
    {
        return $conversation->only(['id', 'title', 'section_id', 'created_at', 'last_message_at']);
    }
}
