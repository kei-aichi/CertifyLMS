<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\QaReply;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

final class QaReplyReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_contains_reply_information_and_multibyte_preview(): void
    {
        $reply = QaReply::factory()->create(['body' => str_repeat('日本語の回答です。', 20)]);
        $notification = new QaReplyReceivedNotification($reply);
        $data = $notification->toArray(User::factory()->student()->create());

        $this->assertSame(['database', 'mail'], $notification->via($reply->user));
        $this->assertSame($reply->qa_thread_id, $data['qa_thread_id']);
        $this->assertSame($reply->id, $data['qa_reply_id']);
        $this->assertLessThanOrEqual(100, mb_strlen($data['body_preview']));
        $this->assertSame('qa-board.show', $data['redirect_route']);
        $this->assertInstanceOf(MailMessage::class, $notification->toMail($reply->user));
        $this->assertStringContainsString($reply->qa_thread_id, $notification->toMail($reply->user)->actionUrl);
    }
}
