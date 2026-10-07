<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaBoard;

use App\Enums\UserStatus;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use App\UseCases\QaReply\StoreAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ReplyNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_notifies_question_author_after_commit_with_data(): void
    {
        Mail::fake();
        $thread = QaThread::factory()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $thread->certification_id,
            'user_id' => $coach->id,
        ]);

        $reply = app(StoreAction::class)($thread, $coach, ['body' => str_repeat('回答本文です。', 20)]);
        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $thread->user_id)
            ->where('type', QaReplyReceivedNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame($thread->id, $notification->data['qa_thread_id']);
        $this->assertSame($reply->id, $notification->data['qa_reply_id']);
        $this->assertSame($coach->id, $notification->data['reply_user_id']);
        $this->assertSame('qa-board.show', $notification->data['redirect_route']);
        $this->assertSame(['thread' => $thread->id], $notification->data['redirect_parameters']);
        $this->assertLessThanOrEqual(100, mb_strlen($notification->data['body_preview']));
    }

    public function test_self_reply_does_not_notify_question_author(): void
    {
        $thread = QaThread::factory()->create();

        app(StoreAction::class)($thread, $thread->user, ['body' => '自己回答']);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_inactive_question_author_does_not_receive_new_notification(): void
    {
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $author = User::factory()->student()->create(['status' => $status]);
            $thread = QaThread::factory()->for($author, 'user')->create();
            $coach = User::factory()->coach()->inProgress()->create();
            CertificationCoachAssignment::factory()->create([
                'certification_id' => $thread->certification_id,
                'user_id' => $coach->id,
            ]);

            app(StoreAction::class)($thread, $coach, ['body' => '回答']);
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_authorization_failure_does_not_create_reply_or_notification(): void
    {
        Notification::fake();
        $thread = QaThread::factory()->create();
        $unauthorized = User::factory()->coach()->inProgress()->create();

        $this->expectException(AuthorizationException::class);
        try {
            app(StoreAction::class)($thread, $unauthorized, ['body' => '不正回答']);
        } finally {
            $this->assertSame(0, QaReply::where('qa_thread_id', $thread->id)->count());
            Notification::assertNothingSent();
        }
    }
}
