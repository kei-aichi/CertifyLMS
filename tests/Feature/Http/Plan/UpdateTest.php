<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Invitation;
use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 基本情報編集は全状態で許可し、既存契約・招待・付与履歴へ遡及しない。 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => '更新プラン', 'description' => '更新説明', 'duration_days' => 123, 'default_meeting_quota' => 7];
    }

    public function test_all_states_allow_edit_and_update_without_changing_related_data(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach (PlanStatus::cases() as $status) {
            $plan = Plan::factory()->create(['status' => $status, 'name' => '編集前プラン', 'description' => '編集前説明', 'duration_days' => 180, 'default_meeting_quota' => 6, 'sort_order' => 9]);
            $other = Plan::factory()->create();
            $student = User::factory()->student()->inProgress()->create(['plan_id' => $plan->id, 'plan_started_at' => now(), 'plan_expires_at' => now()->addDays(30), 'max_meetings' => 4]);
            $invited = User::factory()->invited()->create(['plan_id' => $plan->id]);
            $invitation = Invitation::factory()->forUser($invited)->create();
            $quota = MeetingQuotaTransaction::factory()->create(['user_id' => $student->id]);
            $snapshots = collect([$other, $student, $invited, $invitation, $quota])->map(fn ($model) => [$model, $model->fresh()->getRawOriginal()]);
            $creator = $plan->created_by_user_id;
            $edit = $this->get(route('admin.plans.edit', $plan))->assertOk()->assertViewIs('plan.management.edit');
            $edit->assertSee('value="編集前プラン"', false)->assertSeeText('編集前説明');
            // 同じinput内のnameとvalueを対応付け、項目間の値の入れ替わりも検出する。
            foreach (['duration_days', 'default_meeting_quota', 'sort_order'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/<input\b(?=[^>]*\bname="'.$field.'")(?=[^>]*\bvalue="'.$plan->{$field}.'")[^>]*>/s',
                    $edit->getContent(),
                );
            }
            $input = $this->payload() + ['sort_order' => 13];
            $this->put(route('admin.plans.update', $plan), $input + [
                'status' => $status === PlanStatus::Draft ? 'published' : 'draft',
                'created_by_user_id' => $admin->id, 'updated_by_user_id' => $creator,
            ])->assertRedirect(route('admin.plans.show', $plan))->assertSessionHas('success', 'プランを更新しました。');
            $plan->refresh();
            foreach ($input as $field => $value) {
                $this->assertSame($value, $plan->{$field});
            }
            $this->assertSame($status, $plan->status);
            $this->assertSame($creator, $plan->created_by_user_id);
            $this->assertSame($admin->id, $plan->updated_by_user_id);
            foreach ($snapshots as [$model, $before]) {
                $this->assertSame($before, $model->fresh()->getRawOriginal());
            }
            $this->assertDatabaseCount('invitations', array_search($status, PlanStatus::cases(), true) + 1);
            $this->assertDatabaseCount('meeting_quota_transactions', array_search($status, PlanStatus::cases(), true) + 1);
        }
    }

    /** @dataProvider sortCases */
    public function test_sort_order_update_contract(array $extra, ?int $expected): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = Plan::factory()->create(['sort_order' => 9]);
        $before = $plan->fresh()->getRawOriginal();
        $response = $this->putJson(route('admin.plans.update', $plan), $this->payload() + $extra);
        if ($expected === null) {
            $response->assertUnprocessable()->assertJsonValidationErrors('sort_order');
            $this->assertSame($before, $plan->fresh()->getRawOriginal());
        } else {
            $response->assertRedirect();
            $this->assertSame($expected, $plan->fresh()->sort_order);
        }
    }

    public static function sortCases(): array
    {
        return [
            'omitted' => [[], 9], 'null' => [['sort_order' => null], 9], 'empty' => [['sort_order' => ''], 9],
            'zero' => [['sort_order' => 0], 0], 'positive' => [['sort_order' => 27], 27],
            'maximum' => [['sort_order' => 4294967295], 4294967295],
            'overflow' => [['sort_order' => 4294967296], null], 'negative' => [['sort_order' => -1], null],
            'fraction' => [['sort_order' => 1.5], null], 'text' => [['sort_order' => 'abc'], null],
        ];
    }

    public function test_empty_description_can_be_cleared(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = Plan::factory()->create(['description' => '元の説明']);
        $this->put(route('admin.plans.update', $plan), array_replace($this->payload(), ['description' => '']))->assertRedirect();
        $this->assertNull($plan->fresh()->description);
    }
}
