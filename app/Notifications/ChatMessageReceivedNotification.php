<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChatMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ChatMessageReceivedNotification extends Notification
{
    public function __construct(
        private readonly ChatMessage $message,
        private readonly bool $sendMail = true,
    ) {
        $this->message->loadMissing(['sender', 'chatRoom']);
    }

    public function via(object $notifiable): array
    {
        return $this->sendMail ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'chat_message_received',
            'title' => 'チャットに新着メッセージがあります',
            'message' => $this->message->sender?->name.'さんからメッセージが届きました。',
            'body_preview' => $this->message->body,
            'chat_room_id' => $this->message->chat_room_id,
            'chat_message_id' => $this->message->id,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->sender?->name,
            'redirect_route' => 'chat.show',
            'redirect_parameters' => ['room' => $this->message->chat_room_id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('チャットに新着メッセージがあります')
            ->greeting($notifiable->name.'さん')
            ->line($this->message->sender?->name.'さんからメッセージが届きました。')
            ->line($this->message->body)
            ->action('チャットを確認する', route('chat.show', ['room' => $this->message->chat_room_id]))
            ->salutation('Certify LMS 運営チーム');
    }
}
