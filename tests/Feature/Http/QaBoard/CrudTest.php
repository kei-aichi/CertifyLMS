<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** HTTPから認可・入力検証・永続化まで検証する。GET画面はこのStepでは作成しない。 */
class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_posts_without_enrollment_and_cannot_forge_identity_or_state(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $cert->id, 'title' => '質問', 'body' => '本文',
            'user_id' => User::factory()->create()->id, 'status' => 'resolved', 'resolved_at' => now()->toDateTimeString(),
        ]);
        $thread = QaThread::sole();
        $response->assertRedirect('/qa-board/'.$thread->id)->assertSessionHas('success', '質問を投稿しました。');
        $this->assertSame($student->id, $thread->user_id);
        $this->assertSame($cert->id, $thread->certification_id);
        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);
        $this->assertSame(0, Enrollment::count());
    }

    public function test_thread_edit_only_changes_title_and_body(): void
    {
        $thread = QaThread::factory()->resolved()->create();
        $before = $thread->fresh()->getAttributes();
        $this->actingAs($thread->user)->patch(route('qa-board.update', $thread), [
            'title' => '更新', 'body' => '更新本文', 'certification_id' => Certification::factory()->create()->id,
            'user_id' => User::factory()->create()->id, 'status' => 'open', 'resolved_at' => null,
        ])->assertRedirect('/qa-board/'.$thread->id);
        $thread->refresh();
        $this->assertSame('更新', $thread->title);
        $this->assertSame('更新本文', $thread->body);
        foreach (['certification_id', 'user_id', 'status', 'resolved_at'] as $key) {
            $this->assertSame($before[$key], $thread->getAttributes()[$key]);
        }
        foreach ([User::factory()->student()->create(), User::factory()->admin()->create()] as $other) {
            $this->actingAs($other)->patchJson(route('qa-board.update', $thread), ['title' => '不正', 'body' => '不正'])->assertForbidden();
        }
    }

    public function test_thread_deletion_uses_reply_count_and_admin_cascade(): void
    {
        foreach (['open', 'resolved'] as $state) {
            $thread = QaThread::factory()->{$state}()->create();
            $this->actingAs($thread->user)->delete(route('qa-board.destroy', $thread))->assertRedirect('/qa-board');
            $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        }
        $reply = QaReply::factory()->create();
        $thread = $reply->qaThread;
        $this->actingAs($thread->user)->deleteJson(route('qa-board.destroy', $thread))->assertForbidden();
        $this->actingAs(User::factory()->student()->create())->deleteJson(route('qa-board.destroy', $thread))->assertForbidden();
        $thread->certification->update(['status' => CertificationStatus::Draft]);
        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.qa-board.destroy', $thread))->assertRedirect('/admin/qa-board');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_resolution_and_idempotent_retries_preserve_timestamps(): void
    {
        $thread = QaThread::factory()->create();
        $this->actingAs($thread->user);
        $this->travelTo(now()->startOfSecond());
        $this->post(route('qa-board.resolve', $thread))->assertRedirect('/qa-board/'.$thread->id);
        $thread->refresh();
        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertTrue($thread->resolved_at->equalTo(now()));
        $before = $thread->getAttributes();
        $this->travel(1)->hours();
        $this->postJson(route('qa-board.resolve', $thread))->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertSame($before, $thread->fresh()->getAttributes());
        $this->post(route('qa-board.unresolve', $thread))->assertRedirect();
        $this->assertSame(QaThreadStatus::Open, $thread->fresh()->status);
        $this->assertNull($thread->fresh()->resolved_at);
        // 未解決への再送でもupdated_atを含む全属性を変更しない。
        $openBefore = $thread->fresh()->getAttributes();
        $this->travel(1)->hours();
        $this->postJson(route('qa-board.unresolve', $thread))->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertSame($openBefore, $thread->fresh()->getAttributes());
        $this->post(route('qa-board.resolve', $thread))->assertRedirect();
        $this->assertTrue($thread->fresh()->resolved_at->equalTo(now()));
        $this->assertNotSame($before['resolved_at'], $thread->fresh()->getRawOriginal('resolved_at'));
    }

    public function test_duplicate_operations_require_authorization_in_both_states(): void
    {
        // 目的の状態でも他人・管理者・コーチの代理操作は成功扱いにしない。
        foreach (['open', 'resolved'] as $state) {
            $thread = QaThread::factory()->{$state}()->create();
            $before = $thread->fresh()->getAttributes();
            foreach ([User::factory()->student()->create(), User::factory()->admin()->create(), User::factory()->coach()->create()] as $other) {
                foreach (['resolve', 'unresolve'] as $ability) {
                    $this->actingAs($other)->postJson(route('qa-board.'.$ability, $thread))->assertForbidden();
                }
            }
            $this->assertSame($before, $thread->fresh()->getAttributes());
        }
    }

    public function test_browser_duplicate_operations_redirect_with_success_without_changes(): void
    {
        foreach (['resolved' => 'resolve', 'open' => 'unresolve'] as $state => $ability) {
            $thread = QaThread::factory()->{$state}()->create();
            $before = $thread->fresh()->getAttributes();
            $this->travel(1)->hours();
            $this->actingAs($thread->user)->post(route('qa-board.'.$ability, $thread))
                ->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success')->assertSessionMissing('error');
            $this->assertSame($before, $thread->fresh()->getAttributes());
        }
    }

    public function test_student_and_assigned_coach_reply_crud_never_touches_parent(): void
    {
        foreach (['open', 'resolved'] as $state) {
            $thread = QaThread::factory()->{$state}()->create();
            $student = User::factory()->student()->create();
            $coach = User::factory()->coach()->create();
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id]);
            $before = $thread->fresh()->getAttributes();
            $this->travel(1)->hours();
            foreach ([$student, $coach] as $actor) {
                $this->actingAs($actor)->post(route('qa-board.replies.store', $thread), [
                    'body' => '回答', 'qa_thread_id' => QaThread::factory()->create()->id, 'user_id' => $thread->user_id,
                ])->assertRedirect('/qa-board/'.$thread->id);
                $reply = $thread->replies()->sole();
                $this->assertSame($actor->id, $reply->user_id);
                $this->assertSame($before, $thread->fresh()->getAttributes());
                $this->patch(route('qa-board.replies.update', [$thread, $reply]), [
                    'body' => '更新回答', 'user_id' => $thread->user_id, 'qa_thread_id' => 'forged',
                ])->assertRedirect('/qa-board/'.$thread->id);
                $this->assertSame('更新回答', $reply->fresh()->body);
                $this->assertSame($actor->id, $reply->fresh()->user_id);
                $this->assertSame($thread->id, $reply->fresh()->qa_thread_id);
                $this->assertSame($before, $thread->fresh()->getAttributes());
                $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertRedirect();
                $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
                $this->assertSame($before, $thread->fresh()->getAttributes());
            }
        }
    }

    public function test_reply_other_user_denied_admin_delete_and_nested_binding(): void
    {
        $reply = QaReply::factory()->create();
        $thread = $reply->qaThread;
        $admin = User::factory()->admin()->create();
        foreach ([$thread->user, $admin] as $actor) {
            $this->actingAs($actor)->patchJson(route('qa-board.replies.update', [$thread, $reply]), ['body' => '不正'])->assertForbidden();
        }
        $this->actingAs($thread->user)->deleteJson(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
        // 自分の回答でも、別の親をURLに指定した場合は認可前に404。
        $otherThread = QaThread::factory()->create();
        $this->actingAs($reply->user)->patchJson(route('qa-board.replies.update', [$otherThread, $reply]), ['body' => '不正'])->assertNotFound();
        $this->deleteJson(route('qa-board.replies.destroy', [$otherThread, $reply]))->assertNotFound();
        $before = $thread->fresh()->getAttributes();
        $this->actingAs($admin)->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))->assertRedirect('/admin/qa-board/'.$thread->id);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        $this->assertSame($before, $thread->fresh()->getAttributes());
    }

    public function test_guest_is_rejected_on_all_mutations(): void
    {
        $reply = QaReply::factory()->create();
        $thread = $reply->qaThread;
        foreach ([['post', 'store', []], ['patch', 'update', [$thread]], ['delete', 'destroy', [$thread]], ['post', 'resolve', [$thread]], ['post', 'unresolve', [$thread]], ['post', 'replies.store', [$thread]], ['patch', 'replies.update', [$thread, $reply]], ['delete', 'replies.destroy', [$thread, $reply]]] as [$method,$name,$parameters]) {
            $this->{$method.'Json'}(route('qa-board.'.$name, $parameters), [])->assertUnauthorized();
        }
    }

    public function test_inactive_users_and_unassigned_coach_cannot_mutate(): void
    {
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            foreach (['student', 'coach'] as $role) {
                $user = User::factory()->create(['role' => $role, 'status' => $status]);
                $thread = QaThread::factory()->for($user)->create();
                $reply = QaReply::factory()->for($user)->for($thread, 'qaThread')->create();
                $this->actingAs($user);
                $this->postJson(route('qa-board.store'), ['certification_id' => $thread->certification_id, 'title' => '質問', 'body' => '本文'])->assertForbidden();
                $this->patchJson(route('qa-board.update', $thread), ['title' => '更新', 'body' => '本文'])->assertForbidden();
                foreach (['resolve', 'unresolve'] as $ability) {
                    $this->postJson(route('qa-board.'.$ability, $thread))->assertForbidden();
                }
                $this->deleteJson(route('qa-board.destroy', $thread))->assertForbidden();
                $this->postJson(route('qa-board.replies.store', $thread), ['body' => '回答'])->assertForbidden();
                $this->patchJson(route('qa-board.replies.update', [$thread, $reply]), ['body' => '回答'])->assertForbidden();
                $this->deleteJson(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
            }
        }
        $coach = User::factory()->coach()->create();
        $reply = QaReply::factory()->for($coach)->create();
        $this->actingAs($coach)->postJson(route('qa-board.replies.store', $reply->qaThread), ['body' => '回答'])->assertForbidden();
        $this->patchJson(route('qa-board.replies.update', [$reply->qaThread, $reply]), ['body' => '回答'])->assertForbidden();
        $this->deleteJson(route('qa-board.replies.destroy', [$reply->qaThread, $reply]))->assertForbidden();
    }

    public function test_admin_cannot_post_and_coach_cannot_create_questions(): void
    {
        $thread = QaThread::factory()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        foreach ([$admin, $coach] as $user) {
            $this->actingAs($user)->postJson(route('qa-board.store'), [
                'certification_id' => $thread->certification_id, 'title' => '質問', 'body' => '本文',
            ])->assertForbidden();
        }
        $this->actingAs($admin)->postJson(route('qa-board.replies.store', $thread), ['body' => '回答'])->assertForbidden();
    }

    public function test_non_public_targets_and_invalid_input_are_rejected(): void
    {
        $student = User::factory()->student()->create();
        $this->actingAs($student)->postJson(route('qa-board.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['certification_id', 'title', 'body']);
        foreach ([CertificationStatus::Draft, CertificationStatus::Archived] as $status) {
            $cert = Certification::factory()->create(['status' => $status]);
            $thread = QaThread::factory()->for($cert)->for($student)->create();
            $reply = QaReply::factory()->for($thread, 'qaThread')->for($student)->create();
            $this->postJson(route('qa-board.store'), ['certification_id' => $cert->id, 'title' => '質問', 'body' => '本文'])->assertUnprocessable();
            $this->patchJson(route('qa-board.update', $thread), ['title' => '更新', 'body' => '本文'])->assertForbidden();
            $this->deleteJson(route('qa-board.destroy', $thread))->assertForbidden();
            $this->postJson(route('qa-board.resolve', $thread))->assertForbidden();
            $this->postJson(route('qa-board.unresolve', $thread))->assertForbidden();
            $this->postJson(route('qa-board.replies.store', $thread), ['body' => '回答'])->assertForbidden();
            $this->patchJson(route('qa-board.replies.update', [$thread, $reply]), ['body' => '更新'])->assertForbidden();
            $this->deleteJson(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
        }
    }
}
