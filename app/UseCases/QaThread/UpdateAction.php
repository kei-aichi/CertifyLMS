<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 本文・タイトルのみ編集する。資格・所有者・解決状態を更新対象にしない。
 */
final class UpdateAction
{
    public function __invoke(QaThread $thread, User $user, array $validated): QaThread
    {
        return DB::transaction(function () use ($thread, $user, $validated) {
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            Gate::forUser($user)->authorize('update', $thread);
            $thread->update(['title' => $validated['title'], 'body' => $validated['body']]);

            return $thread;
        });
    }
}
