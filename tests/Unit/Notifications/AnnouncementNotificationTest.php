<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

final class AnnouncementNotificationTest extends TestCase
{
    public function test_database_payload_and_mail_contain_only_announcement_content(): void
    {
        $announcement = Announcement::factory()->create([
            'title' => '重要なお知らせ',
            'body' => '受講生向け本文',
        ]);
        $notification = new AnnouncementNotification($announcement);
        $student = User::factory()->student()->make();

        $data = $notification->toArray($student);
        $mail = $notification->toMail($student);

        $this->assertSame('admin_announcement', $data['notification_type']);
        $this->assertSame($announcement->id, $data['announcement_id']);
        $this->assertSame($announcement->title, $data['title']);
        $this->assertSame($announcement->body, $data['body']);
        $this->assertSame('notifications.show', $data['redirect_route']);
        $this->assertSame([], $data['redirect_parameters']);
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame($announcement->title, $mail->subject);
        $this->assertStringContainsString($announcement->body, $mail->introLines[0]);
    }
}
