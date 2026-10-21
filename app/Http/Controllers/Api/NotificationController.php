<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Notification\IndexRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $user = $request->user();
        $query = $user->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (($request->validated('tab') ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->limit(20)->get();

        return NotificationResource::collection($notifications)->additional([
            'meta' => [
                'tab' => $request->validated('tab') ?? 'all',
                'count' => $notifications->count(),
                'limit' => 20,
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }
}
