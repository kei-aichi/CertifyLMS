<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 回答だけを変更する。親質問へのtouchや状態変更は行わない。
 */
final class DestroyAction
{
    public function __invoke(QaReply $reply, User $user): void
    {
        DB::transaction(function () use ($reply, $user) {
            $reply = QaReply::query()->lockForUpdate()->findOrFail($reply->id);
            Gate::forUser($user)->authorize('delete', $reply);
            $reply->delete();
        });
    }
}
