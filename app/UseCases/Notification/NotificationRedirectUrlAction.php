<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Throwable;

final class NotificationRedirectUrlAction
{
    public function __invoke(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $routeName = $data['redirect_route'] ?? null;
        $parameters = $data['redirect_parameters'] ?? [];
        $allowedRoutes = ['chat.show', 'qa-board.show', 'meetings.show', 'notifications.show'];

        if ($routeName === 'notifications.show') {
            $parameters = ['notification' => $notification->getKey()];
        }

        if (! is_string($routeName)
            || ! in_array($routeName, $allowedRoutes, true)
            || ! is_array($parameters)
            || ! Route::has($routeName)) {
            return route('notifications.index');
        }

        try {
            return route($routeName, $parameters);
        } catch (Throwable) {
            return route('notifications.index');
        }
    }
}
