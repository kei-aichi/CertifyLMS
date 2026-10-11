<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

final class MeetingReminderNotification extends Notification
{
    private const TIMEZONE = 'Asia/Tokyo';

    public function __construct(
        private readonly Meeting $meeting,
        private readonly string $window,
    ) {
        if (! in_array($this->window, ['eve', 'one_hour_before'], true)) {
            throw new InvalidArgumentException('Invalid meeting reminder window.');
        }

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
            'notification_type' => 'meeting_reminder',
            'title' => '面談リマインダー',
            'message' => $this->message(),
            'meeting_id' => $this->meeting->id,
            'redirect_route' => 'meetings.show',
            'redirect_parameters' => ['meeting' => $this->meeting->id],
            'window' => $this->window,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scheduledAt = $this->scheduledAt()->format('Y年n月j日 H:i');

        return (new MailMessage)
            ->subject($this->subject())
            ->greeting($notifiable->name.'さん')
            ->line($this->mailIntro())
            ->line('面談日時: '.$scheduledAt)
            ->action('面談詳細を確認する', route('meetings.show', ['meeting' => $this->meeting->id]))
            ->salutation('Certify LMS 運営チーム');
    }

    private function scheduledAt(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->meeting->scheduled_at)
            ->setTimezone(self::TIMEZONE);
    }

    private function message(): string
    {
        return match ($this->window) {
            'eve' => '明日 '.$this->scheduledAt()->format('Y/m/d H:i').' に面談が予定されています。',
            'one_hour_before' => '本日 '.$this->scheduledAt()->format('H:i').' から面談が始まります（開始1時間前）。',
        };
    }

    private function subject(): string
    {
        return match ($this->window) {
            'eve' => '【面談リマインダー】明日の面談について',
            'one_hour_before' => '【面談リマインダー】面談開始1時間前のお知らせ',
        };
    }

    private function mailIntro(): string
    {
        return match ($this->window) {
            'eve' => '明日の面談をお知らせします。',
            'one_hour_before' => '面談開始1時間前のお知らせです。',
        };
    }
}
