<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 新規プランは必ず下書きとし、監査情報は操作管理者から決定する。
 */
final class StoreAction
{
    public function __invoke(User $admin, array $validated): Plan
    {
        return DB::transaction(fn () => Plan::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'default_meeting_quota' => $validated['default_meeting_quota'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => PlanStatus::Draft->value,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
