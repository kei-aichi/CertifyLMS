<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use InvalidArgumentException;
use Tests\TestCase;

final class MeetingReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eve_notification_has_compatible_database_data_and_mail(): void
    {
        $meeting = Meeting::factory()->reserved()->create([
            'scheduled_at' => '2026-10-11 10:05:00',
        ]);
        $notification = new MeetingReminderNotification($meeting, 'eve');

        $data = $notification->toArray($meeting->student);
        $mail = $notification->toMail($meeting->student);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->student));
        $this->assertSame('meeting_reminder', $data['notification_type']);
        $this->assertSame('明日 2026/10/11 10:05 に面談が予定されています。', $data['message']);
        $this->assertSame('eve', $data['window']);
        $this->assertSame('meetings.show', $data['redirect_route']);
        $this->assertSame(['meeting' => $meeting->id], $data['redirect_parameters']);
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame('【面談リマインダー】明日の面談について', $mail->subject);
        $this->assertStringContainsString($meeting->id, $mail->actionUrl);
        $this->assertStringContainsString('2026年10月11日 10:05', implode(' ', $mail->introLines));
    }

    public function test_one_hour_before_notification_has_window_specific_content(): void
    {
        $meeting = Meeting::factory()->reserved()->create([
            'scheduled_at' => '2026-10-11 10:05:00',
        ]);
        $notification = new MeetingReminderNotification($meeting, 'one_hour_before');

        $data = $notification->toArray($meeting->coach);
        $mail = $notification->toMail($meeting->coach);

        $this->assertSame('本日 10:05 から面談が始まります（開始1時間前）。', $data['message']);
        $this->assertSame('one_hour_before', $data['window']);
        $this->assertSame('【面談リマインダー】面談開始1時間前のお知らせ', $mail->subject);
        $this->assertStringContainsString('2026年10月11日 10:05', implode(' ', $mail->introLines));
    }

    public function test_notification_accepts_explicit_uuid_and_is_not_queued(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $notification = new MeetingReminderNotification($meeting, 'eve');
        $notification->id = '123e4567-e89b-12d3-a456-426614174000';

        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $notification->id);
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_invalid_window_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MeetingReminderNotification(Meeting::factory()->make(), 'invalid');
    }
}
