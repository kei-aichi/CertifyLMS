<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatPendingException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChatConversation\DestroyAction;
use App\UseCases\AiChatConversation\IndexAction;
use App\UseCases\AiChatConversation\ShowAction;
use App\UseCases\AiChatConversation\StoreAction;
use App\UseCases\AiChatConversation\StoreAndReserveAction;
use App\UseCases\AiChatConversation\UpdateAction;
use App\UseCases\AiChatMessage\SendAction as SendMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiChatConversationController extends Controller
{
    public function index(Request $request, IndexAction $action): RedirectResponse|View
    {
        $this->authorize('viewAny', AiChatConversation::class);
        $conversations = $action($request->user());

        return $conversations->isEmpty() ? view('ai-chat.empty-state') : redirect()->route('ai-chat.conversations.show', $conversations->first());
    }

    public function store(StoreConversationRequest $request, StoreAction $action, SendMessageAction $sendAction, StoreAndReserveAction $storeAndReserve): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $initialMessage = $data['message'] ?? null;
        unset($data['message']);
        if ($initialMessage !== null && $initialMessage !== '') {
            try {
                $prepared = $storeAndReserve($request->user(), $data, $initialMessage);
                $conversation = $prepared['conversation'];
                $assistant = $sendAction->sendReserved($request->user(), $conversation, $prepared['message']);
            } catch (AiChatPendingException $exception) {
                abort(409, 'この会話は現在処理中です。');
            } catch (AiChatDailyLimitExceededException $exception) {
                abort(429, '本日のAIチャット利用上限に達しました。');
            } catch (GeminiApiKeyMissingException $exception) {
                abort(503, 'AIチャットは現在利用できません。');
            } catch (GeminiApiException $exception) {
                abort(502, 'AIサービスでエラーが発生しました。');
            }
            $conversation->refresh();
            $payload = [
                'user_message' => $conversation->messages()->where('role', 'user')->latest('created_at')->first(),
                'assistant_message' => $assistant,
                'conversation' => $conversation,
            ];
            if ($request->expectsJson()) {
                return response()->json($payload, 200);
            }

            return redirect()->route('ai-chat.conversations.show', $conversation);
        }

        $conversation = $action($request->user(), $data);
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation], $conversation->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    public function show(Request $request, AiChatConversation $conversation, ShowAction $action): JsonResponse|View
    {
        $this->authorize('view', $conversation);
        $conversation = $action($conversation);
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation->only(['id', 'title', 'section_id']), 'messages' => $conversation->messages->map(fn ($m) => $m->only(['id', 'role', 'status', 'content', 'created_at']))]);
        }

        return view('ai-chat.show', compact('conversation'));
    }

    public function update(UpdateConversationRequest $request, AiChatConversation $conversation, UpdateAction $action): JsonResponse|RedirectResponse
    {
        $conversation = $action($conversation, $request->validated('title'));
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation]);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    public function destroy(Request $request, AiChatConversation $conversation, DestroyAction $action): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $conversation);
        $action($conversation);
        if ($request->expectsJson()) {
            return response()->json([], 204);
        }

        return redirect()->route('ai-chat.index');
    }
}
