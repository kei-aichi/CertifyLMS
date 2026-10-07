<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;
use App\Policies\PlanPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Policy は全状態で admin のみ許可し、状態ごとの業務判定は Action に委ねる。
 */
class PlanPolicyTest extends TestCase
{
    public function test_registered_policy_allows_only_admin_for_every_ability(): void
    {
        $this->assertInstanceOf(PlanPolicy::class, Gate::getPolicyFor(Plan::class));
        foreach (UserRole::cases() as $role) {
            $user = new User(['role' => $role]);
            $gate = Gate::forUser($user);
            foreach (['viewAny', 'create'] as $ability) {
                $this->assertSame($role === UserRole::Admin, $gate->allows($ability, Plan::class));
            }
            foreach (PlanStatus::cases() as $status) {
                $plan = new Plan(['status' => $status]);
                foreach (['view', 'update', 'delete', 'publish', 'archive', 'unarchive'] as $ability) {
                    $this->assertSame($role === UserRole::Admin, $gate->allows($ability, $plan));
                }
            }
        }
    }
}
