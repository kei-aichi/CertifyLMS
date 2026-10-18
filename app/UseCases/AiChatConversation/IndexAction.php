<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class IndexAction
{
    public function __invoke(User $user): Collection
    {
        return AiChatConversation::query()->ownedBy($user)
            ->with(['enrollment.certification', 'section'])
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(30)->get();
    }
}
