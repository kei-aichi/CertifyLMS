<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessNotifications($user);
    }

    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $this->canAccessNotifications($user)
            && $this->owns($user, $notification);
    }

    public function markAsRead(User $user, DatabaseNotification $notification): bool
    {
        return $this->canAccessNotifications($user)
            && $this->owns($user, $notification);
    }

    public function markAllAsRead(User $user): bool
    {
        return $this->canAccessNotifications($user);
    }

    private function canAccessNotifications(User $user): bool
    {
        return in_array($user->status, [UserStatus::InProgress, UserStatus::Graduated], true);
    }

    private function owns(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === $user::class
            && $notification->notifiable_id === $user->getKey();
    }
}
