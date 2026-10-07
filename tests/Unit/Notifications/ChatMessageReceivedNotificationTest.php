<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

final class ChatMessageReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_and_mail_channels_include_message_data_and_safe_link(): void
    {
        $message = ChatMessage::factory()->fromStudent()->create(['body' => '新しいメッセージ']);
        $notification = new ChatMessageReceivedNotification($message);
        $data = $notification->toArray(User::factory()->coach()->create());

        $this->assertSame(['database', 'mail'], $notification->via($message->sender));
        $this->assertSame('chat_message_received', $data['notification_type']);
        $this->assertSame($message->chat_room_id, $data['chat_room_id']);
        $this->assertSame($message->sender_user_id, $data['sender_user_id']);
        $this->assertSame('chat.show', $data['redirect_route']);
        $this->assertInstanceOf(MailMessage::class, $notification->toMail($message->sender));
        $this->assertStringContainsString($message->chat_room_id, $notification->toMail($message->sender)->actionUrl);
    }

    public function test_coach_to_coach_notification_uses_database_only(): void
    {
        $message = ChatMessage::factory()->fromCoach()->create();

        $this->assertSame(['database'], (new ChatMessageReceivedNotification($message, false))->via($message->sender));
    }
}
