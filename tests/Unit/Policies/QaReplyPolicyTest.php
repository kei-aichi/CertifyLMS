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
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 回答の投稿・本人編集・削除と管理者モデレーションの境界を検証する。
 * 親質問が見えない利用者には、回答者本人であっても操作を許可しない。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_is_registered_and_guest_cannot_post(): void
    {
        $thread = QaThread::factory()->create();

        $this->assertInstanceOf(QaReplyPolicy::class, Gate::getPolicyFor(QaReply::class));
        $this->assertFalse(Gate::forUser(null)->allows('create', [QaReply::class, $thread]));
    }

    #[DataProvider('accessCases')]
    public function test_reply_operations_respect_role_publication_and_assignment(UserRole $role, CertificationStatus $status, bool $assigned, bool $expected): void
    {
        $user = User::factory()->create(['role' => $role]);
        $thread = QaThread::factory()->for(Certification::factory()->state(['status' => $status]))->create();
        if ($assigned) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $user->id]);
        }
        $reply = QaReply::factory()->for($thread, 'qaThread')->for($user)->create();

        // 本人であっても投稿時と同じ利用条件が必要。adminは所有者でも編集不可だが削除は可能。
        $this->assertSame($expected, $user->can('create', [QaReply::class, $thread]));
        $this->assertSame($expected, $user->can('update', $reply));
        $this->assertSame($role === UserRole::Admin || $expected, $user->can('delete', $reply));
        $this->assertSame(0, Enrollment::count());
    }

    public static function accessCases(): iterable
    {
        foreach (CertificationStatus::cases() as $status) {
            yield 'student '.$status->value => [UserRole::Student, $status, false, $status === CertificationStatus::Published];
            yield 'assigned coach '.$status->value => [UserRole::Coach, $status, true, $status === CertificationStatus::Published];
            yield 'unassigned coach '.$status->value => [UserRole::Coach, $status, false, false];
            yield 'admin '.$status->value => [UserRole::Admin, $status, false, false];
        }
    }

    #[DataProvider('inactiveCases')]
    public function test_inactive_authors_cannot_create_edit_or_delete(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $reply = QaReply::factory()->for($user)->create();
        if ($role === UserRole::Coach) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $reply->qaThread->certification->id, 'user_id' => $user->id]);
        }

        $this->assertFalse($user->can('create', [QaReply::class, $reply->qaThread]));
        $this->assertFalse($user->can('update', $reply));
        $this->assertFalse($user->can('delete', $reply));
    }

    public static function inactiveCases(): iterable
    {
        foreach ([UserRole::Student, UserRole::Coach] as $role) {
            foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
                yield $role->value.' '.$status->value => [$role, $status];
            }
        }
    }

    public function test_question_author_and_other_users_cannot_modify_someone_elses_reply(): void
    {
        $reply = QaReply::factory()->create();
        $thread = $reply->qaThread;
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $coach->id]);
        $other = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($reply->user->can('update', $reply));
        $this->assertTrue($reply->user->can('delete', $reply));
        // 質問の所有者や担当コーチであることは、他人の回答を編集・削除できる根拠にならない。
        foreach ([$thread->user, $coach, $other] as $actor) {
            $this->assertFalse($actor->can('update', $reply));
            $this->assertFalse($actor->can('delete', $reply));
        }
        $this->assertFalse($admin->can('update', $reply));
        $this->assertTrue($admin->can('delete', $reply));
    }

    public function test_coach_loses_reply_permissions_after_assignment_is_removed(): void
    {
        $coach = User::factory()->coach()->create();
        $reply = QaReply::factory()->for($coach)->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $reply->qaThread->certification->id, 'user_id' => $coach->id]);
        $this->assertTrue($coach->can('update', $reply));
        $this->assertTrue($coach->can('delete', $reply));

        // 過去に投稿した本人でも、現在非担当の資格に対する操作は許可しない。
        $assignment->update(['unassigned_at' => now()]);
        $this->assertFalse($coach->can('create', [QaReply::class, $reply->qaThread]));
        $this->assertFalse($coach->can('update', $reply));
        $this->assertFalse($coach->can('delete', $reply));
    }

    public function test_resolution_state_does_not_limit_reply_permissions(): void
    {
        foreach (['open', 'resolved'] as $state) {
            $thread = QaThread::factory()->{$state}()->create();
            $student = User::factory()->student()->create();
            $coach = User::factory()->coach()->create();
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification->id, 'user_id' => $coach->id]);
            foreach ([$student, $coach] as $author) {
                $reply = QaReply::factory()->for($thread, 'qaThread')->for($author)->create();
                $this->assertTrue($author->can('create', [QaReply::class, $thread]));
                $this->assertTrue($author->can('update', $reply));
                $this->assertTrue($author->can('delete', $reply));
            }
        }
    }
}
