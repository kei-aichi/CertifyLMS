<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Throwable;

/** @mixin DatabaseNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $readAt = $this->read_at;

        return [
            'id' => (string) $this->getKey(),
            'type' => (string) $this->type,
            'notification_type' => $data['notification_type'] ?? null,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '通知',
            'preview' => $this->preview($data),
            'created_at' => $this->created_at?->toIso8601String(),
            'relative_time' => $this->created_at?->diffForHumans(),
            'read_at' => $readAt?->toIso8601String(),
            'is_unread' => $readAt === null,
            'redirect_url' => $this->redirectUrl($data),
        ];
    }

    /** @param array<string, mixed> $data */
    private function preview(array $data): string
    {
        foreach (['message', 'body_preview', 'body'] as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function redirectUrl(array $data): string
    {
        $routeName = $data['redirect_route'] ?? null;
        $parameters = $data['redirect_parameters'] ?? [];
        $allowedRoutes = ['chat.show', 'qa-board.show', 'meetings.show', 'notifications.show'];

        if (! is_string($routeName)
            || ! in_array($routeName, $allowedRoutes, true)
            || ! is_array($parameters)
            || ! Route::has($routeName)) {
            return route('notifications.index');
        }

        if ($routeName === 'notifications.show') {
            $parameters = ['notification' => $this->getKey()];
        }

        try {
            return route($routeName, $parameters);
        } catch (Throwable) {
            return route('notifications.index');
        }
    }
}
