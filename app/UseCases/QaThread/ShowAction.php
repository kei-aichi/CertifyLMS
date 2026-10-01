<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;

/** 回答は投稿順。退会済み投稿者も表示用に取得し、名前の置換は行わない。 */
final class ShowAction
{
    public function __invoke(QaThread $thread, ?User $viewer = null): QaThread
    {
        $thread->load([
            'certification',
            'user' => fn ($query) => $query->withTrashed(),
            'replies' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'replies.user' => fn ($query) => $query->withTrashed(),
        ])->loadCount('replies');

        if ($viewer?->role === UserRole::Coach) {
            // 表示中だけ利用する担当情報のスナップショット。coaches()の解除済み除外条件を維持する。
            // 受講生・管理者の表示や一覧には、この追加取得を持ち込まない。
            $thread->certification->load('coaches:id');
        }

        // 回答Policyが親へ戻る際に、回答ごとの親・資格の再取得を発生させない。
        foreach ($thread->replies as $reply) {
            $reply->setRelation('qaThread', $thread);
        }

        return $thread;
    }
}
