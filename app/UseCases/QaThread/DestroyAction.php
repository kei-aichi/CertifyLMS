<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 質問の物理削除。HTTP受付時の認可結果だけで削除せず、排他区間で再確認する。
 */
final class DestroyAction
{
    public function __invoke(QaThread $thread, User $user): void
    {
        DB::transaction(function () use ($thread, $user) {
            // 回答投稿も同じ親行をロックする。待機後の最新の回答数で本人削除を再認可する。
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            Gate::forUser($user)->authorize('delete', $thread);
            // 管理者の削除ではFK cascadeで回答も物理削除する。
            $thread->delete();
        });
    }
}
