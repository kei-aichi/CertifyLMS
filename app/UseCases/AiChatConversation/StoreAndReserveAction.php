<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\UseCases\AiChatMessage\ReserveAction;
use Illuminate\Support\Facades\DB;

final class StoreAndReserveAction
{
    public function __construct(
        private readonly StoreAction $store,
        private readonly ReserveAction $reserve,
    ) {}

    /** @return array{conversation: AiChatConversation, message: AiChatMessage} */
    public function __invoke(User $user, array $conversationData, string $content): array
    {
        return DB::transaction(function () use ($user, $conversationData, $content): array {
            $conversation = ($this->store)($user, $conversationData);
            $message = ($this->reserve)($user, $conversation, $content);

            return ['conversation' => $conversation, 'message' => $message];
        });
    }
}
