<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QaReply;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class QaReplyReceivedNotification extends Notification
{
    public function __construct(private readonly QaReply $reply)
    {
        $this->reply->loadMissing(['qaThread', 'user']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'qa_reply_received',
            'title' => '質問に回答が投稿されました',
            'message' => $this->reply->user?->name.'さんが質問に回答しました。',
            'body_preview' => Str::limit(trim($this->reply->body), 100),
            'qa_thread_id' => $this->reply->qa_thread_id,
            'qa_reply_id' => $this->reply->id,
            'reply_user_id' => $this->reply->user_id,
            'reply_user_name' => $this->reply->user?->name,
            'redirect_route' => 'qa-board.show',
            'redirect_parameters' => ['thread' => $this->reply->qa_thread_id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('質問に回答が投稿されました')
            ->greeting($notifiable->name.'さん')
            ->line($this->reply->user?->name.'さんが質問に回答しました。')
            ->line(Str::limit(trim($this->reply->body), 100))
            ->action('質問を確認する', route('qa-board.show', ['thread' => $this->reply->qa_thread_id]))
            ->salutation('Certify LMS 運営チーム');
    }
}
