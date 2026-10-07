<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function __invoke(User $user, string $tab): LengthAwarePaginator
    {
        $query = $user->notifications()->orderByDesc('created_at')->orderByDesc('id');

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        return $query->paginate(20)->withQueryString();
    }
}
