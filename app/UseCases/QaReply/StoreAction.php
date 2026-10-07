<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 回答先・投稿者をサーバーで決め、質問削除との競合を親行のロックで直列化する。
 */
final class StoreAction
{
    public function __invoke(QaThread $thread, User $user, array $validated): QaReply
    {
        return DB::transaction(function () use ($thread, $user, $validated) {
            // 質問削除と同じ親行をロックする。削除が先なら404となり回答は残らない。
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            Gate::forUser($user)->authorize('create', [QaReply::class, $thread]);

            // 親をsave/touchしない。解決状態・解決日時・更新日時は質問自身の操作だけで変わる。
            $reply = $thread->replies()->create(['user_id' => $user->id, 'body' => $validated['body']]);

            DB::afterCommit(function () use ($reply): void {
                $reply->loadMissing(['qaThread.user', 'user']);
                $questionAuthor = $reply->qaThread?->user;

                if ($questionAuthor === null
                    || $questionAuthor->id === $reply->user_id
                    || $questionAuthor->status !== UserStatus::InProgress) {
                    return;
                }

                $questionAuthor->notify(new QaReplyReceivedNotification($reply));
            });

            return $reply;
        });
    }
}
