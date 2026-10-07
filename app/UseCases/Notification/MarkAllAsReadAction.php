<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

final class MarkAllAsReadAction
{
    public function __invoke(User $user): void
    {
        $user->unreadNotifications()->whereNull('read_at')->update(['read_at' => now()]);
    }
}
