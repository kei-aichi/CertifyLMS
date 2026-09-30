<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板の認可。受講登録は条件にせず、資格の公開状態・コーチの現役担当を確認する。
 * 管理者は閲覧・削除のみ許可し、投稿・代理編集・代理解決は許可しない。
 */
class QaThreadPolicy
{
    public function viewAny(User $user): bool
    {
        // 一覧への入口の判定。公開資格・担当資格による取得対象の絞り込みは一覧処理の責務。
        return $user->role === UserRole::Admin
            || ($user->status === UserStatus::InProgress
                && in_array($user->role, [UserRole::Student, UserRole::Coach], true));
    }

    public function view(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->status !== UserStatus::InProgress
            || $thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            UserRole::Student => true,
            // coaches() は unassigned_at が NULL の現役割当だけを返す。
            UserRole::Coach => $thread->certification->relationLoaded('coaches')
                // 詳細表示で一括取得した現役担当を共有する。未ロードの更新リクエストはDBで確認する。
                ? $thread->certification->coaches->contains('id', $user->id)
                : $thread->certification->coaches()->where('users.id', $user->id)->exists(),
            default => false,
        };
    }

    public function create(User $user, ?Certification $certification = null): bool
    {
        // 資格未選択の作成リンクにも使用する。保存時の認可では対象資格を必ず渡す。
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && ($certification === null || $certification->status === CertificationStatus::Published);
    }

    public function update(User $user, QaThread $thread): bool
    {
        // 本人でも利用期間終了後・資格公開停止後は操作できない。
        return $user->role === UserRole::Student
            && $thread->user_id === $user->id
            && $this->view($user, $thread);
    }

    public function delete(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        // 回答のある質問を本人が消すことは禁止。解決状態は削除条件に含めない。
        // 表示用の回答数キャッシュに依存せず、認可時点の存在を確認する。
        return $this->update($user, $thread) && ! $thread->replies()->exists();
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        // 回答0件でも自己解決できる。現在の状態によらず本人の操作を認可する。
        return $this->update($user, $thread);
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        // 提供済みBladeのability名に合わせる。日時のクリアや状態変更はここでは行わない。
        return $this->update($user, $thread);
    }
}
