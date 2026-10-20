<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

class AiChatConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->eligible($user);
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $this->eligible($user) && $conversation->user_id === $user->id;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function sendMessage(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    private function eligible(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && (bool) config('ai-chat.enabled');
    }
}
