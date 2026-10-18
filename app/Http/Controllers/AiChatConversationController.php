<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use Illuminate\Http\Request;

class AiChatConversationController extends Controller
{
    public function index(Request $request): never
    {
        $this->authorize('viewAny', AiChatConversation::class);
        abort(501, 'AIチャット会話一覧はStep 3で実装します。');
    }

    public function store(StoreConversationRequest $request): never
    {
        abort(501, 'AIチャット会話作成はStep 3で実装します。');
    }

    public function show(AiChatConversation $conversation): never
    {
        $this->authorize('view', $conversation);
        abort(501, 'AIチャット会話詳細はStep 3で実装します。');
    }

    public function update(UpdateConversationRequest $request, AiChatConversation $conversation): never
    {
        abort(501, 'AIチャット会話更新はStep 3で実装します。');
    }

    public function destroy(Request $request, AiChatConversation $conversation): never
    {
        $this->authorize('delete', $conversation);
        abort(501, 'AIチャット会話削除はStep 3で実装します。');
    }
}
