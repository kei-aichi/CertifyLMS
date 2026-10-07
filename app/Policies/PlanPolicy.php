<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

/**
 * プラン管理は admin 専用。PM回答待ちの状態・参照条件はここでは判定しない。
 */
class PlanPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function update(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function delete(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function publish(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function archive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function unarchive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
