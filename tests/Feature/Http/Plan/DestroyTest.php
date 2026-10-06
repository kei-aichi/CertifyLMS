<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use App\UseCases\Plan\DestroyAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 削除は未参照の下書きだけ。履歴と退会済みユーザーの参照も保護する。 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unreferenced_draft_is_physically_deleted_without_affecting_other_data(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = Plan::factory()->draft()->create();
        $other = Plan::factory()->create();
        $user = User::factory()->invited()->create(['plan_id' => $other->id]);
        $invitation = Invitation::factory()->forUser($user)->create();
        $log = UserPlanLog::factory()->create(['user_id' => $user->id, 'plan_id' => $other->id]);
        $snapshots = collect([$other, $user, $invitation, $log])->map(fn ($model) => [$model, $model->fresh()->getRawOriginal()]);
        $this->delete(route('admin.plans.destroy', $plan))->assertRedirect(route('admin.plans.index'))
            ->assertSessionHas('success', 'プランを削除しました。');
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        foreach ($snapshots as [$model, $before]) {
            $this->assertSame($before, $model->fresh()->getRawOriginal());
        }
    }

    public static function blockedCases(): array
    {
        $cases = ['published' => [PlanStatus::Published, null, null, false, false], 'archived' => [PlanStatus::Archived, null, null, false, false], 'history only' => [PlanStatus::Draft, null, null, false, true]];
        foreach (UserRole::cases() as $role) {
            foreach (UserStatus::cases() as $status) {
                $cases[$role->value.' '.$status->value] = [PlanStatus::Draft, $role, $status, false, false];
            }
        }
        $cases['soft deleted'] = [PlanStatus::Draft, UserRole::Student, UserStatus::InProgress, true, false];

        return $cases;
    }

    #[DataProvider('blockedCases')]
    public function test_blocked_deletion_keeps_data_and_returns_html_or_json_error(PlanStatus $status, ?UserRole $role, ?UserStatus $userStatus, bool $deleted, bool $history): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = Plan::factory()->create(['status' => $status]);
        $related = null;
        if ($role !== null) {
            $related = User::factory()->create(['plan_id' => $plan->id, 'role' => $role, 'status' => $userStatus]);
            if ($deleted) {
                $related->delete();
            }
        } elseif ($history) {
            // User自体は対象Planを参照させず、履歴だけによる拒否を保証する。
            $related = UserPlanLog::factory()->create(['plan_id' => $plan->id, 'user_id' => User::factory()->create(['plan_id' => null])->id]);
        }
        $before = $plan->fresh()->getRawOriginal();
        $relatedBefore = $related?->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $url = route('admin.plans.destroy', $plan);
        $back = route('admin.plans.show', $plan);
        $this->from($back)->delete($url, ['status' => 'draft'])->assertRedirect($back)->assertSessionHas('error')->assertSessionMissing('success');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->deleteJson($url)->assertConflict()->assertSessionMissing('success');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        if ($related !== null) {
            $this->assertSame($relatedBefore, $related->fresh()->getRawOriginal());
        }
    }

    public function test_latest_db_status_is_used_instead_of_stale_model(): void
    {
        $plan = Plan::factory()->draft()->create();
        Plan::whereKey($plan->id)->update(['status' => PlanStatus::Published->value]);
        $before = $plan->fresh()->getRawOriginal();
        try {
            app(DestroyAction::class)($plan);
            $this->fail('最新状態が公開中なら削除を拒否する必要があります。');
        } catch (PlanNotDeletableException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $stale = $plan->fresh();
        Plan::whereKey($plan->id)->update(['status' => PlanStatus::Draft->value]);
        app(DestroyAction::class)($stale);
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    #[DataProvider('foreignKeys')]
    public function test_database_restricts_direct_deletion_with_references(bool $history): void
    {
        $plan = Plan::factory()->draft()->create();
        $user = User::factory()->create(['plan_id' => $history ? null : $plan->id]);
        $log = $history ? UserPlanLog::factory()->create(['plan_id' => $plan->id, 'user_id' => $user->id]) : null;
        try {
            // Actionを迂回してもDBのFK自体が孤児化を防ぐ。
            DB::transaction(fn () => DB::table('plans')->where('id', $plan->id)->delete());
            $this->fail('外部キーによる削除拒否が必要です。');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertSame(1451, $e->errorInfo[1]);
        }
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        if ($log !== null) {
            $this->assertDatabaseHas('user_plan_logs', ['id' => $log->id]);
        }
    }

    public static function foreignKeys(): array
    {
        return ['users' => [false], 'history' => [true]];
    }
}
