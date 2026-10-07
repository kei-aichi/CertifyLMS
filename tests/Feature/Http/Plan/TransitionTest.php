<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Invitation;
use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 状態変更は3遷移のみ許可し、二重操作でも監査情報を変更しない。
 */
class TransitionTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            ['publish', PlanStatus::Draft, PlanStatus::Published, 'プランを公開しました。'],
            ['archive', PlanStatus::Published, PlanStatus::Archived, 'プランをアーカイブしました。'],
            ['unarchive', PlanStatus::Archived, PlanStatus::Draft, 'プランを下書きへ戻しました。'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transition_succeeds_and_repeat_is_rejected(string $operation, PlanStatus $from, PlanStatus $to, string $message): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create(['status' => $from]);
        $creator = $plan->created_by_user_id;
        // 状態・更新者・更新日時とは分けて、基本情報はDB再取得値で不変を保証する。
        $basicFields = ['name', 'description', 'duration_days', 'default_meeting_quota', 'sort_order'];
        $basicBefore = $plan->fresh()->only($basicFields);
        // 状態変更はマスタだけに作用し、既存契約・招待・付与履歴へ遡及しない。
        $other = Plan::factory()->create();
        $student = User::factory()->student()->create(['plan_id' => $plan->id]);
        $invited = User::factory()->invited()->create(['plan_id' => $plan->id]);
        $invitation = Invitation::factory()->forUser($invited)->create();
        $quota = MeetingQuotaTransaction::factory()->create(['user_id' => $student->id]);
        $snapshots = collect([$other, $student, $invited, $invitation, $quota])
            ->map(fn ($model) => [$model, $model->fresh()->getRawOriginal()]);
        $url = route('admin.plans.'.$operation, $plan);
        $this->actingAs($admin)->post($url, [
            'status' => $from->value, 'created_by_user_id' => $admin->id, 'updated_by_user_id' => $creator,
        ])
            ->assertRedirect(route('admin.plans.show', $plan))
            ->assertSessionHas('success', $message);
        $plan->refresh();
        $this->assertSame($basicBefore, $plan->only($basicFields));
        $this->assertSame($to, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
        $this->assertSame($creator, $plan->created_by_user_id);
        foreach ($snapshots as [$model, $before]) {
            $this->assertSame($before, $model->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('invitations', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);

        // 時刻と操作管理者を変えて再送し、拒否時に更新者・更新日時も保持されることを確認する。
        $before = $plan->getRawOriginal();
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->admin()->create())->postJson($url)->assertConflict();
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public static function invalidTransitions(): array
    {
        return [
            ['publish', PlanStatus::Published, '下書き状態のプランのみ公開できます。'],
            ['publish', PlanStatus::Archived, '下書き状態のプランのみ公開できます。'],
            ['archive', PlanStatus::Draft, '公開中のプランのみアーカイブできます。'],
            ['archive', PlanStatus::Archived, '公開中のプランのみアーカイブできます。'],
            ['unarchive', PlanStatus::Draft, 'アーカイブ状態のプランのみ下書きへ戻せます。'],
            ['unarchive', PlanStatus::Published, 'アーカイブ状態のプランのみ下書きへ戻せます。'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_preserves_data_for_html_and_json(string $operation, PlanStatus $status, string $message): void
    {
        $plan = Plan::factory()->create(['status' => $status]);
        $before = $plan->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->admin()->create());
        $url = route('admin.plans.'.$operation, $plan);
        $back = route('admin.plans.show', $plan);

        // 同じ業務例外を、既存Handlerがリクエスト形式に応じて変換する。
        $this->from($back)->post($url)->assertRedirect($back)->assertSessionHas('error', $message)
            ->assertSessionMissing('success');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->postJson($url)->assertConflict()->assertJsonPath('message', $message)
            ->assertSessionMissing('success');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }
}
