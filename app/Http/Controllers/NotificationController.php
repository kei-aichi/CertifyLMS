<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Notification\IndexRequest;
use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $this->authorize('viewAny', DatabaseNotification::class);
        $tab = $request->validated('tab') ?? 'all';
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $action($user, $tab),
            'unreadCount' => $user->unreadNotifications()->count(),
            'tab' => $tab,
        ]);
    }

    public function markAsRead(DatabaseNotification $notification, MarkAsReadAction $action): RedirectResponse
    {
        $this->authorize('markAsRead', $notification);

        return redirect()->to($action(Auth::user(), $notification));
    }

    public function markAllAsRead(MarkAllAsReadAction $action): RedirectResponse
    {
        $this->authorize('markAllAsRead', DatabaseNotification::class);
        $action(Auth::user());

        return redirect()->route('notifications.index');
    }
}
