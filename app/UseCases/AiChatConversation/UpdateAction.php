<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;

final class UpdateAction
{
    public function __invoke(AiChatConversation $conversation, string $title): AiChatConversation
    {
        $conversation->update(['title' => $title, 'title_manually_edited_at' => now(), 'auto_title_enabled' => false]);

        return $conversation->refresh();
    }
}
