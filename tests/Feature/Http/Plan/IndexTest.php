<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 管理一覧の検索・集計・並び順を、提供済みBlade経由で保証する。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_index_includes_all_states_without_filters(): void
    {
        foreach (PlanStatus::cases() as $status) {
            Plan::factory()->create(['status' => $status]);
        }
        $response = $this->get(route('admin.plans.index'))->assertOk()
            ->assertViewIs('plan.management.index');
        $this->assertSame(3, $response->viewData('plans')->total());
        $this->assertSame(3, preg_match_all('/>0 名<\/span>/', $response->getContent()));
    }

    public function test_keyword_searches_only_name_and_does_not_split_spaces(): void
    {
        $match = Plan::factory()->create(['name' => '追加 面談パック']);
        Plan::factory()->create(['name' => '別の商品', 'description' => '追加 面談']);
        Plan::factory()->create(['name' => '追加サポート面談']);
        $response = $this->get(route('admin.plans.index', ['keyword' => '追加 面談']))->assertOk();
        $this->assertSame([$match->id], $response->viewData('plans')->pluck('id')->all());
        $response->assertViewHas('keyword', '追加 面談');
    }

    public function test_zero_is_a_search_keyword(): void
    {
        $match = Plan::factory()->create(['name' => '10回']);
        Plan::factory()->create(['name' => '単回']);
        $response = $this->get(route('admin.plans.index', ['keyword' => '0']))->assertOk();
        $this->assertSame([$match->id], $response->viewData('plans')->pluck('id')->all());
    }

    public function test_each_status_filter_and_keyword_are_combined_with_and(): void
    {
        foreach (PlanStatus::cases() as $status) {
            Plan::factory()->create(['name' => '対象パック', 'status' => $status]);
            Plan::factory()->create(['name' => 'その他', 'status' => $status]);
        }
        foreach (PlanStatus::cases() as $status) {
            $response = $this->get(route('admin.plans.index', ['status' => $status->value]))->assertOk();
            $this->assertSame(2, $response->viewData('plans')->total());
            $this->assertTrue($response->viewData('plans')->every(fn ($plan) => $plan->status === $status));
            $response->assertViewHas('status', $status->value);

            $combined = $this->get(route('admin.plans.index', ['status' => $status->value, 'keyword' => '対象']))->assertOk();
            $this->assertSame(1, $combined->viewData('plans')->total());
            $this->assertSame($status, $combined->viewData('plans')->first()->status);
            $this->assertSame('対象パック', $combined->viewData('plans')->first()->name);
        }
    }

    public function test_order_uses_sort_order_then_created_at_then_id(): void
    {
        // 作成順に頼らず、3段階すべての優先順位を区別できるデータを用意する。
        $base = ['sort_order' => 10, 'created_at' => '2026-01-02 00:00:00'];
        $lowId = Plan::factory()->create($base + ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FA0']);
        $highId = Plan::factory()->create($base + ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FA1']);
        $old = Plan::factory()->create(['sort_order' => 10, 'created_at' => '2026-01-01 00:00:00']);
        $first = Plan::factory()->create(['sort_order' => 1, 'created_at' => '2025-01-01 00:00:00']);
        $last = Plan::factory()->create(['sort_order' => 20, 'created_at' => '2026-02-01 00:00:00']);
        $response = $this->get(route('admin.plans.index'))->assertOk();
        $this->assertSame([$first->id, $highId->id, $lowId->id, $old->id, $last->id], $response->viewData('plans')->pluck('id')->all());
    }

    public function test_pagination_keeps_filters_and_returns_twenty_per_page(): void
    {
        Plan::factory()->count(21)->published()->create(['name' => '対象パック']);
        Plan::factory()->draft()->create(['name' => '対象パック']);
        $filters = ['keyword' => '対象', 'status' => 'published'];
        $response = $this->get(route('admin.plans.index', $filters))->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(21, $plans->total());
        $this->assertSame(20, $plans->perPage());
        $this->assertCount(20, $plans);
        parse_str(parse_url($plans->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);
        $next = $this->get($plans->nextPageUrl())->assertOk()->viewData('plans');
        $this->assertCount(1, $next);
        $this->assertSame(2, $next->currentPage());
        $this->assertFalse($plans->pluck('id')->contains($next->first()->id));
    }

    public function test_counts_only_current_students_of_each_plan(): void
    {
        $plan = Plan::factory()->create();
        $other = Plan::factory()->create();
        User::factory()->student()->inProgress()->count(2)->create(['plan_id' => $plan->id]);
        User::factory()->student()->inProgress()->create(['plan_id' => $other->id]);
        // 状態とSoftDeleteは独立に検証し、どちらか一方だけの条件では通らないデータにする。
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            User::factory()->student()->create(['plan_id' => $plan->id, 'status' => $status]);
        }
        $deleted = User::factory()->student()->inProgress()->create(['plan_id' => $plan->id]);
        $deleted->delete();
        foreach ([UserRole::Coach, UserRole::Admin] as $role) {
            User::factory()->inProgress()->create(['plan_id' => $plan->id, 'role' => $role]);
        }
        $plans = $this->get(route('admin.plans.index'))->assertOk()->viewData('plans')->keyBy('id');
        $this->assertSame(2, $plans[$plan->id]->users_count);
        $this->assertSame(1, $plans[$other->id]->users_count);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        foreach ([['keyword' => ['invalid']], ['keyword' => str_repeat('あ', 101)], ['status' => 'unknown'], ['page' => 0], ['page' => -1], ['page' => 1.5], ['page' => 'abc']] as $input) {
            $this->getJson(route('admin.plans.index', $input))->assertUnprocessable()
                ->assertJsonValidationErrors(array_key_first($input));
        }
    }

    public function test_user_counts_do_not_add_queries_per_plan(): void
    {
        foreach ([1, 10] as $size) {
            Plan::factory()->create(['name' => '件数確認'.$size]);
            if ($size > 1) {
                Plan::factory()->count($size - 1)->create(['name' => '件数確認'.$size]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $plans = app(IndexAction::class)('件数確認'.$size, null);
                foreach ($plans as $plan) {
                    $this->assertSame(0, $plan->users_count);
                }
                // ページ総件数と一覧の2SQLのみ。人数参照による追加照会を許容しない。
                $this->assertCount(2, DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }
}
