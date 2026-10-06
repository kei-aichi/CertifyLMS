<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 下書き・アーカイブの面談パックを物理削除する。購入履歴は削除条件に含めない。
 */
final class DestroyAction
{
    public function __invoke(MeetingPack $plan): void
    {
        DB::transaction(function () use ($plan) {
            // 状態変更と同じ行をロックし、Binding後に公開されたパックを削除しない。
            $plan = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status === MeetingPackStatus::Published) {
                throw new MeetingPackNotDeletableException;
            }

            $plan->delete();
        });
    }
}
