<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use App\Services\InvitationTokenService;
use App\UseCases\Auth\IssueInvitationAction;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use App\UseCases\Plan\ArchiveAction;
use App\UseCases\Plan\PublishAction;
use App\UseCases\Plan\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_transitions_update_invitation_and_extension_choices(): void
    {
        // 管理操作後の選択肢と直接送信の両方で、非公開プランを新規割当に使えない。
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $plan = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $student = User::factory()->inProgress()->withPlan($published)->create();

        foreach ([null, PublishAction::class, ArchiveAction::class, UnarchiveAction::class] as $action) {
            if ($action !== null) {
                app($action)($plan, $admin);
            }
            $expected = $action === PublishAction::class ? [$published->id, $plan->id] : [$published->id];
            $index = $this->get(route('admin.users.index'))->assertOk();
            $show = $this->get(route('admin.users.show', $student))->assertOk();
            $this->assertEqualsCanonicalizing($expected, $index->viewData('inviteFormPlans')->modelKeys());
            $this->assertEqualsCanonicalizing($expected, $show->viewData('plans')->modelKeys());
            if ($action !== PublishAction::class) {
                $this->postJson(route('admin.invitations.store'), [
                    'email' => 'blocked@example.test', 'role' => 'student', 'plan_id' => $plan->id,
                ])->assertUnprocessable()->assertJsonValidationErrors('plan_id');
                $this->postJson(route('admin.users.extendCourse', $student), ['plan_id' => $plan->id])
                    ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
            }
        }
    }

    public function test_invitation_issued_before_archive_is_not_rejected_by_onboarding(): void
    {
        // 正式な招待発行後にアーカイブしても、署名付きURLから登録できる。
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create(['duration_days' => 90, 'default_meeting_quota' => 4]);
        $invitation = app(IssueInvitationAction::class)('invited@example.test', UserRole::Student, $plan, $admin);
        app(ArchiveAction::class)($plan, $admin);
        $this->get(app(InvitationTokenService::class)->generateUrl($invitation))->assertOk()->assertViewIs('auth.onboarding');
        $url = URL::temporarySignedRoute('onboarding.store', $invitation->expires_at, ['invitation' => $invitation->id]);
        $this->post($url, ['name' => '登録受講生', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertRedirect(route('dashboard.index'))->assertSessionHasNoErrors();
        $user = $invitation->user->fresh();
        // PM承認により、既存のUser.status更新漏れは別チケットで対応する。
        // 招待のaccepted更新も既存処理では未実施のため、S-B-03の合否条件には含めない。
        // ここではアーカイブを理由に拒否されず、他の登録属性が保存される契約を保証する。
        $this->assertSame('登録受講生', $user->name);
        $this->assertTrue($user->profile_setup_completed);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame($invitation->user_id, $user->id);
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertSame(90, (int) $user->plan_started_at->diffInDays($user->plan_expires_at));
        $this->assertSame(PlanStatus::Archived, $plan->fresh()->status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_edit_and_archive_preserve_contract_history_and_dashboard_plan(): void
    {
        // マスタ変更は契約・履歴へ遡及せず、非公開になっても既存契約のプラン名は取得できる。
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->inProgress()->withPlan($plan)->create();
        $log = UserPlanLog::factory()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);
        $userBefore = $student->fresh()->getRawOriginal();
        $logBefore = $log->fresh()->getRawOriginal();
        $this->put(route('admin.plans.update', $plan), [
            'name' => '変更後プラン', 'description' => null, 'duration_days' => 365,
            'default_meeting_quota' => 0, 'sort_order' => 0,
        ])->assertRedirect(route('admin.plans.show', $plan));
        app(ArchiveAction::class)($plan, $admin);
        $this->assertSame($userBefore, $student->fresh()->getRawOriginal());
        $this->assertSame($logBefore, $log->fresh()->getRawOriginal());
        $this->assertDatabaseCount('user_plan_logs', 1);
        $this->assertSame($plan->id, $log->fresh()->plan->id);
        $info = app(FetchStudentDashboardAction::class)($student->fresh())->planInfo;
        $this->assertNotNull($info);
        $this->assertSame('変更後プラン', $info->planName);
    }

    public function test_archived_plan_does_not_change_automatic_graduation_criteria(): void
    {
        // 卒業判定はマスタの公開状態ではなく、既存Userの契約期限に基づく。
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        $expired = User::factory()->inProgress()->withPlan($plan)->create(['plan_expires_at' => now()->subDay()]);
        $active = User::factory()->inProgress()->withPlan($plan)->create(['plan_expires_at' => now()->addDay()]);
        app(ArchiveAction::class)($plan, $admin);
        $this->artisan('users:graduate-expired')->assertSuccessful();
        $this->assertSame(UserStatus::Graduated, $expired->fresh()->status);
        $this->assertSame(UserStatus::InProgress, $active->fresh()->status);
        $this->assertDatabaseHas('user_plan_logs', ['user_id' => $expired->id, 'plan_id' => $plan->id]);
    }
}
