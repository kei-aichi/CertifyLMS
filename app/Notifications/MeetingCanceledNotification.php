<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MeetingCanceledNotification extends Notification
{
    public function __construct(private readonly Meeting $meeting)
    {
        $this->meeting->loadMissing(['coach', 'student', 'canceledBy']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'meeting_canceled',
            'title' => '面談がキャンセルされました',
            'message' => '面談がキャンセルされました。',
            'meeting_id' => $this->meeting->id,
            'canceled_by_user_id' => $this->meeting->canceled_by_user_id,
            'canceled_by_name' => $this->meeting->canceledBy?->name,
            'coach_user_id' => $this->meeting->coach_id,
            'student_user_id' => $this->meeting->student_id,
            'redirect_route' => 'meetings.show',
            'redirect_parameters' => ['meeting' => $this->meeting->id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('面談がキャンセルされました')
            ->greeting($notifiable->name.'さん')
            ->line('面談がキャンセルされました。')
            ->line('キャンセル実行者: '.($this->meeting->canceledBy?->name ?? '不明'))
            ->action('面談詳細を確認する', route('meetings.show', ['meeting' => $this->meeting->id]))
            ->salutation('Certify LMS 運営チーム');
    }
}
