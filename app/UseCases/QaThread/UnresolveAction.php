<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 状態と解決日時を同一トランザクションで更新し、目的の状態なら変更せず成功する。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread, User $user): QaThread
    {
        return DB::transaction(function () use ($thread, $user) {
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            // 目的の状態でも認可は省略せず、権限のない操作を拒否する。
            Gate::forUser($user)->authorize('unresolve', $thread);
            if ($thread->status === QaThreadStatus::Open) {
                // 再送では解決日時・更新日時を保持するため、保存処理を行わない。
                return $thread;
            }
            $thread->update(['status' => QaThreadStatus::Open, 'resolved_at' => null]);

            return $thread;
        });
    }
}
