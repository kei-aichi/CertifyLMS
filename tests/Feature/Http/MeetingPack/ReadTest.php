<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 提供済み Blade のまま管理一覧・詳細を表示でき、購入基盤を必要としないことを保証する。
 */
class ReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_index_includes_all_states_without_filters_and_displays_zero_purchases(): void
    {
        foreach (MeetingPackStatus::cases() as $status) {
            MeetingPack::factory()->create(['status' => $status]);
        }
        $response = $this->get(route('admin.meeting-packs.index'))->assertOk()
            ->assertViewIs('meeting-pack.management.index');
        $this->assertSame(3, $response->viewData('plans')->total());
        $this->assertFalse(class_exists('App\\Models\\Payment'));
        $this->assertSame(3, preg_match_all('/>0 件<\/span>/', $response->getContent()));
    }

    public function test_keyword_searches_only_name_and_does_not_split_spaces(): void
    {
        $match = MeetingPack::factory()->create(['name' => '追加 面談パック']);
        MeetingPack::factory()->create(['name' => '別の商品', 'description' => '追加 面談']);
        MeetingPack::factory()->create(['name' => '追加サポート面談']);
        $response = $this->get(route('admin.meeting-packs.index', ['keyword' => '追加 面談']))->assertOk();
        $this->assertSame([$match->id], $response->viewData('plans')->pluck('id')->all());
        $response->assertViewHas('keyword', '追加 面談');
    }

    public function test_zero_is_a_search_keyword(): void
    {
        $match = MeetingPack::factory()->create(['name' => '10回']);
        MeetingPack::factory()->create(['name' => '単回']);
        $response = $this->get(route('admin.meeting-packs.index', ['keyword' => '0']))->assertOk();
        $this->assertSame([$match->id], $response->viewData('plans')->pluck('id')->all());
    }

    public function test_each_status_filter_and_keyword_are_combined_with_and(): void
    {
        foreach (MeetingPackStatus::cases() as $status) {
            MeetingPack::factory()->create(['name' => '対象パック', 'status' => $status]);
            MeetingPack::factory()->create(['name' => 'その他', 'status' => $status]);
        }
        foreach (MeetingPackStatus::cases() as $status) {
            $response = $this->get(route('admin.meeting-packs.index', ['status' => $status->value]))->assertOk();
            $this->assertSame(2, $response->viewData('plans')->total());
            $this->assertTrue($response->viewData('plans')->every(fn ($plan) => $plan->status === $status));
            $response->assertViewHas('status', $status->value);

            $combined = $this->get(route('admin.meeting-packs.index', ['status' => $status->value, 'keyword' => '対象']))->assertOk();
            $this->assertSame(1, $combined->viewData('plans')->total());
            $this->assertSame($status, $combined->viewData('plans')->first()->status);
            $this->assertSame('対象パック', $combined->viewData('plans')->first()->name);
        }
    }

    public function test_order_uses_sort_order_then_created_at_then_id(): void
    {
        // 作成順に頼らず、3段階すべての優先順位を区別できるデータを用意する。
        $base = ['sort_order' => 10, 'created_at' => '2026-01-02 00:00:00'];
        $lowId = MeetingPack::factory()->create($base + ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FA0']);
        $highId = MeetingPack::factory()->create($base + ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FA1']);
        $old = MeetingPack::factory()->create(['sort_order' => 10, 'created_at' => '2026-01-01 00:00:00']);
        $first = MeetingPack::factory()->create(['sort_order' => 1, 'created_at' => '2025-01-01 00:00:00']);
        $last = MeetingPack::factory()->create(['sort_order' => 20, 'created_at' => '2026-02-01 00:00:00']);
        $response = $this->get(route('admin.meeting-packs.index'))->assertOk();
        $this->assertSame([$first->id, $highId->id, $lowId->id, $old->id, $last->id], $response->viewData('plans')->pluck('id')->all());
    }

    public function test_pagination_keeps_filters_and_returns_twenty_per_page(): void
    {
        MeetingPack::factory()->count(21)->published()->create(['name' => '対象パック']);
        MeetingPack::factory()->draft()->create(['name' => '対象パック']);
        $filters = ['keyword' => '対象', 'status' => 'published'];
        $response = $this->get(route('admin.meeting-packs.index', $filters))->assertOk();
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

    public function test_detail_loads_both_administrators_and_displays_empty_purchase_history(): void
    {
        $creator = User::factory()->admin()->create(['name' => '作成担当']);
        $updater = User::factory()->admin()->create(['name' => '更新担当']);
        $plan = MeetingPack::factory()->create(['created_by_user_id' => $creator->id, 'updated_by_user_id' => $updater->id]);
        $response = $this->get(route('admin.meeting-packs.show', $plan))->assertOk()
            ->assertViewIs('meeting-pack.management.show')
            ->assertSee('作成担当')->assertSee('更新担当')
            ->assertSee('この SKU の購入はまだありません。');
        $loaded = $response->viewData('plan');
        $this->assertTrue($loaded->is($plan));
        $this->assertTrue($loaded->relationLoaded('createdBy'));
        $this->assertTrue($loaded->relationLoaded('updatedBy'));
        $this->assertTrue($loaded->createdBy->is($creator));
        $this->assertTrue($loaded->updatedBy->is($updater));
        $this->assertMatchesRegularExpression('/購入数\s+0\s+件/u', strip_tags($response->getContent()));
    }

    public function test_show_action_leaves_no_lazy_queries_for_metadata(): void
    {
        $plan = MeetingPack::factory()->create();
        $loaded = app(ShowAction::class)($plan->fresh());
        // 取得後にメタ情報を参照しても追加SQLを実行しない。
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertNotNull($loaded->createdBy->name);
            $this->assertNotNull($loaded->updatedBy->name);
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
