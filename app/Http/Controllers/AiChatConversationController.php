<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChatConversation\DestroyAction;
use App\UseCases\AiChatConversation\IndexAction;
use App\UseCases\AiChatConversation\ShowAction;
use App\UseCases\AiChatConversation\StoreAction;
use App\UseCases\AiChatConversation\UpdateAction;
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

    public function store(StoreConversationRequest $request, StoreAction $action): JsonResponse|RedirectResponse
    {
        $conversation = $action($request->user(), $request->validated());
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
