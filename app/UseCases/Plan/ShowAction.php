<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;

final class ShowAction
{
    public function __invoke(Plan $plan): Plan
    {
        // 一覧の契約中人数と同じ対象に限定する。SoftDeletesの既存スコープも維持する。
        return $plan->load([
            'createdBy',
            'updatedBy',
            'users' => fn ($query) => $query->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress),
        ]);
    }
}
