<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class MarkAsReadAction
{
    public function __invoke(User $user, DatabaseNotification $notification): string
    {
        Gate::forUser($user)->authorize('markAsRead', $notification);

        $notification->markAsRead();

        return (new NotificationRedirectUrlAction())($notification);
    }
}
