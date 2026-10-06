<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 全状態で基本情報を編集できる。公開状態と作成者は基本情報編集では変更しない。
 */
final class UpdateAction
{
    public function __invoke(Plan $plan, User $admin, array $validated): Plan
    {
        return DB::transaction(function () use ($plan, $admin, $validated) {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'duration_days' => $validated['duration_days'],
                'default_meeting_quota' => $validated['default_meeting_quota'],
                // 未指定・null は既存順序を維持し、明示的な 0 は更新する。
                'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
