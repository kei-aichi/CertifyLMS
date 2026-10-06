<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(Plan $plan): void
    {
        DB::transaction(function () use ($plan) {
            // 状態変更と同じ親行をロックし、Binding後の変更を反映して判定する。
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            // 退会済みUserもDB上の参照を保持する。ロール・利用状態では絞らない。
            if ($plan->status !== PlanStatus::Draft
                || $plan->users()->withTrashed()->exists()
                || $plan->userPlanLogs()->exists()) {
                throw new PlanNotDeletableException;
            }

            // 新規参照との競合時も、既存のRESTRICT外部キーが参照整合性を保証する。
            $plan->delete();
        });
    }
}
