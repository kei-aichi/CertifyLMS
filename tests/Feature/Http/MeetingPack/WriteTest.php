<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 基本情報の保存と、クライアントに委ねない状態・操作管理者の記録を保証する。
 */
class WriteTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => '新しい面談パック', 'description' => '説明', 'meeting_count' => 8,
            'price' => 12000, 'stripe_price_id' => 'price_test', 'sort_order' => 7];
    }

    public function test_admin_can_create_and_server_controls_status_and_audit_users(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.meeting-packs.create'))
            ->assertOk()->assertViewIs('meeting-pack.management.create');

        $response = $this->post(route('admin.meeting-packs.store'), $this->payload() + [
            'status' => 'published', 'created_by_user_id' => $other->id, 'updated_by_user_id' => $other->id,
        ]);
        $plan = MeetingPack::sole();
        $response->assertRedirect(route('admin.meeting-packs.show', $plan))
            ->assertSessionHas('success', '面談パックを作成しました。');
        $this->assertDatabaseHas('meeting_packs', $this->payload() + [
            'id' => $plan->id, 'status' => 'draft',
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id,
        ]);
    }

    public static function sortOrders(): array
    {
        return ['未指定' => [[], 0, 9], 'null' => [['sort_order' => null], 0, 9],
            '明示的な0' => [['sort_order' => 0], 0, 0], '指定値' => [['sort_order' => 4], 4, 4]];
    }

    #[DataProvider('sortOrders')]
    public function test_sort_order_defaults_on_create_and_preserves_on_update(array $input, int $created, int $updated): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $payload = $this->payload();
        unset($payload['sort_order']);
        $this->post(route('admin.meeting-packs.store'), $payload + $input)->assertRedirect();
        $this->assertSame($created, MeetingPack::sole()->sort_order);

        // 更新では null も維持を意味し、0 への変更とは区別する。
        $plan = MeetingPack::factory()->create(['sort_order' => 9]);
        $this->patch(route('admin.meeting-packs.update', $plan), $payload + $input)->assertRedirect();
        $this->assertSame($updated, $plan->fresh()->sort_order);
    }

    public static function statuses(): array
    {
        return ['下書き' => [MeetingPackStatus::Draft], '公開中' => [MeetingPackStatus::Published],
            'アーカイブ' => [MeetingPackStatus::Archived]];
    }

    #[DataProvider('statuses')]
    public function test_all_states_allow_basic_edits_without_changing_status_or_creator(MeetingPackStatus $status): void
    {
        $creator = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->create([
            'status' => $status, 'created_by_user_id' => $creator->id, 'updated_by_user_id' => $creator->id,
            'meeting_count' => 1, 'price' => 100,
        ]);
        $this->actingAs($admin)->get(route('admin.meeting-packs.edit', $plan))
            ->assertOk()->assertViewIs('meeting-pack.management.edit')->assertViewHas('plan', $plan);

        // 公開状態の変更は専用操作の責務であり、基本情報と一緒に送信しても採用しない。
        $this->patch(route('admin.meeting-packs.update', $plan), $this->payload() + [
            'status' => $status === MeetingPackStatus::Draft ? 'published' : 'draft',
            'created_by_user_id' => $admin->id, 'updated_by_user_id' => $creator->id,
        ])->assertRedirect(route('admin.meeting-packs.show', $plan))
            ->assertSessionHas('success', '面談パックを更新しました。');
        $this->assertDatabaseHas('meeting_packs', $this->payload() + [
            'id' => $plan->id, 'status' => $status->value,
            'created_by_user_id' => $creator->id, 'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_optional_fields_are_null_when_omitted_or_empty_on_create(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $payload = $this->payload();
        unset($payload['description'], $payload['stripe_price_id']);
        foreach ([[], ['description' => '', 'stripe_price_id' => '']] as $optional) {
            $this->post(route('admin.meeting-packs.store'), $payload + $optional)->assertRedirect();
        }
        $this->assertDatabaseCount('meeting_packs', 2);
        foreach (MeetingPack::all() as $plan) {
            $this->assertNull($plan->description);
            $this->assertNull($plan->stripe_price_id);
        }
    }

    public function test_empty_optional_fields_clear_existing_values_on_update(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = MeetingPack::factory()->create(['description' => '説明', 'stripe_price_id' => 'price_old']);
        // HTTP の空文字変換を通して、任意項目を消去できることを保証する。
        $this->patch(route('admin.meeting-packs.update', $plan), array_replace($this->payload(), [
            'description' => '', 'stripe_price_id' => '',
        ]))->assertRedirect();
        $this->assertNull($plan->fresh()->description);
        $this->assertNull($plan->fresh()->stripe_price_id);
    }
}
