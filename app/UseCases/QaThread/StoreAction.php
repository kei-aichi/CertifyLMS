<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 質問投稿。検証後の公開停止も再確認し、所有者・初期状態はサーバーで固定する。
 */
final class StoreAction
{
    public function __invoke(User $user, array $validated): QaThread
    {
        return DB::transaction(function () use ($user, $validated) {
            $certification = Certification::query()->lockForUpdate()->findOrFail($validated['certification_id']);
            Gate::forUser($user)->authorize('create', [QaThread::class, $certification]);

            return QaThread::create([
                'certification_id' => $certification->id,
                'user_id' => $user->id,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'status' => QaThreadStatus::Open,
                'resolved_at' => null,
            ]);
        });
    }
}
