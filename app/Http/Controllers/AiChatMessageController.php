<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Models\AiChatConversation;

class AiChatMessageController extends Controller
{
    public function store(StoreMessageRequest $request, AiChatConversation $conversation): never
    {
        abort(501, 'AIチャットメッセージ送信はStep 5で実装します。');
    }
}
