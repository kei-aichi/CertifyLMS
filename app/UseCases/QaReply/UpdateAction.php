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
final class UpdateAction
{
    public function __invoke(QaReply $reply, User $user, array $validated): QaReply
    {
        return DB::transaction(function () use ($reply, $user, $validated) {
            $reply = QaReply::query()->lockForUpdate()->findOrFail($reply->id);
            Gate::forUser($user)->authorize('update', $reply);
            $reply->update(['body' => $validated['body']]);

            return $reply;
        });
    }
}
