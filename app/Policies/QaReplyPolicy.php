<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 回答の認可。質問の閲覧条件を共有し、本人のみ編集・削除できる。
 * 管理者にはモデレーション削除だけを許可し、回答投稿・代理編集は許可しない。
 */
class QaReplyPolicy
{
    public function __construct(private readonly QaThreadPolicy $threadPolicy) {}

    public function create(User $user, QaThread $thread): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $this->threadPolicy->view($user, $thread);
    }

    public function update(User $user, QaReply $reply): bool
    {
        // 所有者判定だけにせず、利用状態・公開状態・現在の担当資格も再確認する。
        return $reply->user_id === $user->id
            && $this->create($user, $reply->qaThread);
    }

    public function delete(User $user, QaReply $reply): bool
    {
        return $user->role === UserRole::Admin || $this->update($user, $reply);
    }
}
