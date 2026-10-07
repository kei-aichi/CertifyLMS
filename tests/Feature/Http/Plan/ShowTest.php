<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 提供済み詳細画面の契約中受講者とメタ情報を保証する。
 * 認証・認可と存在しないIDはAuthorizationTestで全管理Routeを検証する。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_show_displays_basic_information_and_loaded_metadata(): void
    {
        $creator = User::factory()->admin()->create(['name' => '作成担当']);
        $updater = User::factory()->admin()->create(['name' => '更新担当']);
        $plan = Plan::factory()->published()->create([
            'name' => '詳細確認プラン', 'description' => '詳細説明',
            'duration_days' => 123, 'default_meeting_quota' => 17,
            'created_by_user_id' => $creator->id, 'updated_by_user_id' => $updater->id,
            'created_at' => '2026-01-02 03:04:00',
        ]);
        $response = $this->get(route('admin.plans.show', $plan))->assertOk()
            ->assertViewIs('plan.management.show')
            ->assertSeeText(['詳細確認プラン', '詳細説明', '123', '17', '公開中', '作成担当', '更新担当', '2026-01-02 03:04']);
        $shown = $response->viewData('plan');
        foreach (['createdBy', 'updatedBy', 'users'] as $relation) {
            $this->assertTrue($shown->relationLoaded($relation));
        }
        $this->assertTrue($shown->createdBy->is($creator));
        $this->assertTrue($shown->updatedBy->is($updater));
    }

    public function test_show_includes_only_current_students_without_pagination(): void
    {
        $plan = Plan::factory()->create();
        // 20件を超えても詳細画面では全員を渡す。残面談回数は既存max_meetings表示を維持する。
        $students = User::factory()->student()->inProgress()->count(21)->create([
            'plan_id' => $plan->id, 'max_meetings' => 37, 'plan_expires_at' => '2027-02-03',
        ]);
        $excluded = collect();
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $excluded->push(User::factory()->student()->create(['plan_id' => $plan->id, 'status' => $status]));
        }
        // 利用状態とは独立してSoftDeleteによる除外を保証する。
        $deleted = User::factory()->student()->inProgress()->create(['plan_id' => $plan->id]);
        $deleted->delete();
        $excluded->push($deleted);
        foreach ([UserRole::Coach, UserRole::Admin] as $role) {
            $excluded->push(User::factory()->inProgress()->create(['plan_id' => $plan->id, 'role' => $role]));
        }
        $excluded->push(User::factory()->student()->inProgress()->create(['plan_id' => Plan::factory()]));
        $response = $this->get(route('admin.plans.show', $plan))->assertOk();
        $shown = $response->viewData('plan');
        $this->assertEqualsCanonicalizing($students->modelKeys(), $shown->users->modelKeys());
        foreach ($students as $student) {
            $response->assertSeeText($student->email);
        }
        foreach ($excluded as $user) {
            $response->assertDontSeeText($user->email);
        }
        $response->assertSeeText('2027-02-03');
        $this->assertSame(21, preg_match_all('/>37<\/span>/', $response->getContent()));
    }

    public function test_missing_metadata_uses_existing_fallback(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create(['created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);
        $admin->delete();
        $response = $this->get(route('admin.plans.show', $plan))->assertOk();
        // 片方の代替表示だけで成功しないよう、各ラベルに対応する表示欄を検証する。
        foreach (['作成者', '最終更新者'] as $label) {
            $this->assertMatchesRegularExpression(
                '/<dt\b[^>]*>\s*'.$label.'\s*<\/dt>\s*<dd\b[^>]*>\s*—\s*<\/dd>/u',
                $response->getContent(),
            );
        }
        $this->assertNull($response->viewData('plan')->createdBy);
        $this->assertNull($response->viewData('plan')->updatedBy);
    }
}
