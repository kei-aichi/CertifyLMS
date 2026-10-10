<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AnnouncementNotification extends Notification
{
    public function __construct(private readonly Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'admin_announcement',
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'redirect_route' => 'notifications.show',
            'redirect_parameters' => [],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->announcement->title)
            ->greeting($notifiable->name.'さん')
            ->line($this->announcement->body)
            ->salutation('Certify LMS 運営チーム');
    }
}
