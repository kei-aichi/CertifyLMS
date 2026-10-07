<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランをアーカイブ（published → archived）。二重操作も状態競合として拒否する。
 */
final class ArchiveAction
{
    /**
     * @throws PlanInvalidTransitionException
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        return DB::transaction(function () use ($plan, $admin) {
            // Binding 時点の状態を信用せず、競合する状態変更を直列化してから判定する。
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status !== PlanStatus::Published) {
                throw PlanInvalidTransitionException::forArchive();
            }

            $plan->update([
                'status' => PlanStatus::Archived->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
