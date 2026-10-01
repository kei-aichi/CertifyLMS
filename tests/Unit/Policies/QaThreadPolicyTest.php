<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 質問のロール・利用状態・資格公開状態・本人条件を検証する。
 * User::can() を経由し、Policyの登録と既存Bladeからの呼び出し方も保証する。
 * 状態遷移・冪等性・更新内容の検証はActionテストで扱う。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_is_registered_and_guest_is_denied(): void
    {
        $thread = QaThread::factory()->create();

        $this->assertInstanceOf(QaThreadPolicy::class, Gate::getPolicyFor(QaThread::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $thread));
        $this->assertFalse(Gate::forUser(null)->allows('create', QaThread::class));
    }

    #[DataProvider('viewCases')]
    public function test_view_respects_role_publication_and_assignment(UserRole $role, CertificationStatus $status, bool $assigned, bool $expected): void
    {
        $user = User::factory()->create(['role' => $role]);
        $certification = Certification::factory()->create(['status' => $status]);
        $thread = QaThread::factory()->for($certification)->create();
        if ($assigned) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $certification->id, 'user_id' => $user->id]);
        }

        // 一覧入口の許可と、個別質問の公開・担当制限は別の判定。
        $this->assertTrue($user->can('viewAny', QaThread::class));
        $this->assertSame($expected, $user->can('view', $thread));
        $this->assertSame(0, Enrollment::count(), '掲示板の閲覧に受講登録を要求しない');
    }

    public static function viewCases(): iterable
    {
        foreach (CertificationStatus::cases() as $status) {
            yield 'student '.$status->value => [UserRole::Student, $status, false, $status === CertificationStatus::Published];
            yield 'assigned coach '.$status->value => [UserRole::Coach, $status, true, $status === CertificationStatus::Published];
            yield 'unassigned coach '.$status->value => [UserRole::Coach, $status, false, false];
            yield 'admin '.$status->value => [UserRole::Admin, $status, false, true];
        }
    }

    #[DataProvider('inactiveCases')]
    public function test_inactive_student_and_coach_cannot_use_board(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $thread = QaThread::factory()->for($user)->create();
        if ($role === UserRole::Coach) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $user->id]);
        }

        // 本人・担当者であっても、in_progress以外は機能群共通の利用条件を満たさない。
        $this->assertFalse($user->can('viewAny', QaThread::class));
        $this->assertFalse($user->can('create', QaThread::class));
        $this->assertFalse($user->can('create', [QaThread::class, $thread->certification]));
        foreach (['view', 'update', 'delete', 'resolve', 'unresolve'] as $ability) {
            $this->assertFalse($user->can($ability, $thread), $ability);
        }
    }

    public static function inactiveCases(): iterable
    {
        foreach ([UserRole::Student, UserRole::Coach] as $role) {
            foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
                yield $role->value.' '.$status->value => [$role, $status];
            }
        }
    }

    public function test_question_creation_is_student_only_and_requires_publication_when_target_is_supplied(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        // 既存Bladeは資格未選択でcreateを呼ぶ。これは作成画面への入口だけの認可。
        $this->assertTrue($student->can('create', QaThread::class));
        $this->assertFalse($coach->can('create', QaThread::class));
        $this->assertFalse($admin->can('create', QaThread::class));

        foreach (CertificationStatus::cases() as $status) {
            $certification = Certification::factory()->create(['status' => $status]);
            CertificationCoachAssignment::factory()->create(['certification_id' => $certification->id, 'user_id' => $coach->id]);

            // 保存対象を渡した場合は公開資格のみ。担当コーチにも質問の新規投稿は許可しない。
            $this->assertSame($status === CertificationStatus::Published, $student->can('create', [QaThread::class, $certification]));
            $this->assertFalse($coach->can('create', [QaThread::class, $certification]));
            $this->assertFalse($admin->can('create', [QaThread::class, $certification]));
        }
        $this->assertSame(0, Enrollment::count());
    }

    public function test_only_question_author_can_edit_and_change_resolution(): void
    {
        $thread = QaThread::factory()->create();
        $other = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $coach->id]);

        foreach (['update', 'resolve', 'unresolve'] as $ability) {
            $this->assertTrue($thread->user->can($ability, $thread), $ability);
            foreach ([$other, $coach, $admin] as $actor) {
                $this->assertFalse($actor->can($ability, $thread), $ability.' '.$actor->role->value);
            }
        }
        $this->assertFalse($other->can('delete', $thread));
        $this->assertFalse($coach->can('delete', $thread));
    }

    public function test_non_public_questions_are_not_editable_by_their_author(): void
    {
        foreach ([CertificationStatus::Draft, CertificationStatus::Archived] as $status) {
            $thread = QaThread::factory()->for(Certification::factory()->state(['status' => $status]))->create();
            foreach (['update', 'delete', 'resolve', 'unresolve'] as $ability) {
                $this->assertFalse($thread->user->can($ability, $thread), $ability);
            }
        }
    }

    public function test_removed_assignment_is_not_treated_as_current_assignment(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $coach->id]);
        $this->assertTrue($coach->can('view', $thread));

        // 同じModelを使い続けても、解除済みの割当でアクセスが継続しない。
        $assignment->update(['unassigned_at' => now()]);
        $this->assertFalse($coach->can('view', $thread));
    }

    #[DataProvider('threadStates')]
    public function test_author_delete_depends_on_current_replies_not_resolution_state(string $state): void
    {
        $thread = QaThread::factory()->{$state}()->create();
        $admin = User::factory()->admin()->create();
        $this->assertTrue($thread->user->can('delete', $thread));
        $this->assertTrue($admin->can('delete', $thread));

        // 古い表示用件数が0でも、回答追加後は本人削除を拒否する。
        $thread->load('replies')->loadCount('replies');
        $reply = QaReply::factory()->for($thread, 'qaThread')->create();
        $this->assertFalse($thread->user->can('delete', $thread));
        $this->assertTrue($admin->can('delete', $thread));

        $reply->delete();
        $this->assertTrue($thread->user->can('delete', $thread));
    }

    public static function threadStates(): iterable
    {
        yield 'open' => ['open'];
        yield 'resolved' => ['resolved'];
    }

    #[DataProvider('threadStates')]
    public function test_resolution_authorization_does_not_perform_or_block_state_transitions(string $state): void
    {
        $thread = QaThread::factory()->{$state}()->create();
        $before = $thread->fresh()->getAttributes();

        // 回答0件でも解決操作を認可。目的の状態への再操作も同じ認可条件を適用する。
        $this->assertTrue($thread->user->can('resolve', $thread));
        $this->assertTrue($thread->user->can('unresolve', $thread));
        QaReply::factory()->for($thread, 'qaThread')->create();
        $this->assertTrue($thread->user->can('resolve', $thread));
        $this->assertTrue($thread->user->can('unresolve', $thread));
        $this->assertSame($before, $thread->fresh()->getAttributes());
    }

    public function test_admin_can_moderate_non_public_questions_but_cannot_edit_even_as_author(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([CertificationStatus::Draft, CertificationStatus::Archived] as $status) {
            $thread = QaThread::factory()->for($admin)->for(Certification::factory()->state(['status' => $status]))->create();
            $this->assertTrue($admin->can('view', $thread));
            $this->assertTrue($admin->can('delete', $thread));
            QaReply::factory()->for($thread, 'qaThread')->create();
            $this->assertTrue($admin->can('delete', $thread));
            // before()等のadmin一括許可で、禁止された代理編集・解決が復活しないことを確認する。
            foreach (['update', 'resolve', 'unresolve'] as $ability) {
                $this->assertFalse($admin->can($ability, $thread), $ability);
            }
        }
    }
}
