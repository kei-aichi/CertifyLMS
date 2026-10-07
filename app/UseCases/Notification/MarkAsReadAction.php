<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Throwable;

final class MarkAsReadAction
{
    public function __invoke(User $user, DatabaseNotification $notification): string
    {
        Gate::forUser($user)->authorize('markAsRead', $notification);

        $notification->markAsRead();

        return $this->redirectUrl($notification);
    }

    private function redirectUrl(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $routeName = $data['redirect_route'] ?? null;
        $parameters = $data['redirect_parameters'] ?? [];
        $allowedRoutes = [
            'chat.show',
            'qa-board.show',
            'meetings.show',
        ];

        if (! is_string($routeName) || ! in_array($routeName, $allowedRoutes, true) || ! is_array($parameters) || ! Route::has($routeName)) {
            return route('notifications.index');
        }

        try {
            return route($routeName, $parameters);
        } catch (Throwable) {
            return route('notifications.index');
        }
    }
}
