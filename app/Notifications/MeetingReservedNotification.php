<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MeetingReservedNotification extends Notification
{
    public function __construct(private readonly Meeting $meeting)
    {
        $this->meeting->loadMissing(['coach', 'student']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'meeting_reserved',
            'title' => '面談が予約されました',
            'message' => '面談日時: '.$this->meeting->scheduled_at?->format('Y年n月j日 H:i'),
            'meeting_id' => $this->meeting->id,
            'coach_user_id' => $this->meeting->coach_id,
            'coach_name' => $this->meeting->coach?->name,
            'student_user_id' => $this->meeting->student_id,
            'student_name' => $this->meeting->student?->name,
            'redirect_route' => 'meetings.show',
            'redirect_parameters' => ['meeting' => $this->meeting->id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('面談が予約されました')
            ->greeting($notifiable->name.'さん')
            ->line('面談が予約されました。')
            ->line('面談日時: '.$this->meeting->scheduled_at?->format('Y年n月j日 H:i'))
            ->action('面談詳細を確認する', route('meetings.show', ['meeting' => $this->meeting->id]))
            ->salutation('Certify LMS 運営チーム');
    }
}
