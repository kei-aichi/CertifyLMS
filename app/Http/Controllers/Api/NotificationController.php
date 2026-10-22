<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Notification\IndexRequest;
use App\Http\Resources\NotificationResource;
use App\UseCases\Notification\MarkAsReadAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

    public function markAsRead(
        Request $request,
        DatabaseNotification $notification,
        MarkAsReadAction $action,
    ): NotificationResource {
        $this->authorize('markAsRead', $notification);
        $action($request->user(), $notification);

        return (new NotificationResource($notification->fresh()))->additional([
            'meta' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): JsonResponse
    {
        $this->authorize('markAllAsRead', DatabaseNotification::class);
        $user = $request->user();
        $updatedCount = $action($user);

        return response()->json([
            'data' => [
                'updated_count' => $updatedCount,
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }
}
