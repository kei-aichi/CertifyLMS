<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Chat;

use App\Jobs\Chat\ChatDatabaseNotificationJob;
use App\Jobs\Chat\ChatMailNotificationJob;
use App\Jobs\Chat\DispatchChatNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

final class ChatNotificationJobsTest extends TestCase
{
    public function test_all_chat_jobs_are_queueable(): void
    {
        self::assertContains(ShouldQueue::class, class_implements(DispatchChatNotifications::class));
        self::assertContains(ShouldQueue::class, class_implements(ChatDatabaseNotificationJob::class));
        self::assertContains(ShouldQueue::class, class_implements(ChatMailNotificationJob::class));
    }

    public function test_database_notification_id_is_stable_per_event_and_recipient(): void
    {
        $first = ChatDatabaseNotificationJob::notificationId('message-1', 'user-1');
        $second = ChatDatabaseNotificationJob::notificationId('message-1', 'user-1');
        $otherRecipient = ChatDatabaseNotificationJob::notificationId('message-1', 'user-2');
        $otherMessage = ChatDatabaseNotificationJob::notificationId('message-2', 'user-1');

        self::assertSame($first, $second);
        self::assertNotSame($first, $otherRecipient);
        self::assertNotSame($first, $otherMessage);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
    }

    public function test_dispatcher_and_channel_jobs_define_exclusive_locks(): void
    {
        $dispatcher = new DispatchChatNotifications('message-1');
        $database = new ChatDatabaseNotificationJob('message-1', 'user-1');
        $mail = new ChatMailNotificationJob('message-1', 'user-1');

        self::assertCount(1, $dispatcher->middleware());
        self::assertCount(1, $database->middleware());
        self::assertCount(1, $mail->middleware());
        self::assertStringContainsString('chat-dispatch:message-1', (string) $dispatcher->middleware()[0]->getLockKey($dispatcher));
        self::assertStringContainsString('chat-db:message-1:user-1', (string) $database->middleware()[0]->getLockKey($database));
        self::assertStringContainsString('chat-mail:message-1:user-1', (string) $mail->middleware()[0]->getLockKey($mail));
    }
}
