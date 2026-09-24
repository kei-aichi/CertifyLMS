<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 状態と解決日時を同一トランザクションで更新し、同時の二重操作も拒否する。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread, User $user): QaThread
    {
        return DB::transaction(function () use ($thread, $user) {
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            // 他人の操作は403。本人の二重操作だけを409にするため認可を先に行う。
            Gate::forUser($user)->authorize('unresolve', $thread);
            if ($thread->status !== QaThreadStatus::Resolved) {
                throw new ConflictHttpException('質問はすでに未解決です。');
            }
            $thread->update(['status' => QaThreadStatus::Open, 'resolved_at' => null]);

            return $thread;
        });
    }
}
