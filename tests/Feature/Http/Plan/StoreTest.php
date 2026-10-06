<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 作成時の保存値と管理フィールド偽装防止をHTTP経由で保証する。
 * 共通項目の文字数・数値境界はRequestTestでも検証する。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function payload(): array
    {
        return ['name' => '作成プラン', 'description' => '説明', 'duration_days' => 90, 'default_meeting_quota' => 4];
    }

    public function test_store_uses_authenticated_admin_and_draft_despite_forged_fields(): void
    {
        $other = User::factory()->admin()->create();
        $input = $this->payload() + ['sort_order' => 12];
        $response = $this->post(route('admin.plans.store'), $input + [
            'status' => 'published', 'created_by_user_id' => $other->id, 'updated_by_user_id' => $other->id,
        ]);
        $this->assertDatabaseCount('plans', 1);
        $plan = Plan::query()->sole();
        foreach ($input as $field => $value) {
            $this->assertSame($value, $plan->{$field});
        }
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($this->admin->id, $plan->created_by_user_id);
        $this->assertSame($this->admin->id, $plan->updated_by_user_id);
        $response->assertRedirect(route('admin.plans.show', $plan))
            ->assertSessionHas('success', 'プランを作成しました。');
    }

    /** @dataProvider sortCases */
    public function test_sort_order_defaults_and_validates(array $extra, ?int $expected): void
    {
        $response = $this->postJson(route('admin.plans.store'), $this->payload() + $extra);
        if ($expected === null) {
            $response->assertUnprocessable()->assertJsonValidationErrors('sort_order');
            $this->assertDatabaseCount('plans', 0);
        } else {
            $response->assertRedirect();
            $this->assertSame($expected, Plan::query()->sole()->sort_order);
        }
    }

    public static function sortCases(): array
    {
        return [
            'omitted' => [[], 0],
            'null' => [['sort_order' => null], 0],
            'empty form' => [['sort_order' => ''], 0],
            'zero' => [['sort_order' => 0], 0],
            'positive' => [['sort_order' => 27], 27],
            'numeric string' => [['sort_order' => '27'], 27],
            // DBのINT UNSIGNED上限は実保存まで、上限超過は保存前の拒否まで保証する。
            'unsigned int maximum' => [['sort_order' => 4294967295], 4294967295],
            'unsigned int overflow' => [['sort_order' => 4294967296], null],
            'negative' => [['sort_order' => -1], null],
            'fraction' => [['sort_order' => 1.5], null],
            'text' => [['sort_order' => 'abc'], null],
        ];
    }

    public function test_missing_name_is_rejected_without_creating_plan(): void
    {
        $input = $this->payload();
        unset($input['name']);
        $this->postJson(route('admin.plans.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('plans', 0);
    }
}
