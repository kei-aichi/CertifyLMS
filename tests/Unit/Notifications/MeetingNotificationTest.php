<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Meeting;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

final class MeetingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserved_notification_contains_meeting_data_and_safe_link(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $notification = new MeetingReservedNotification($meeting);
        $data = $notification->toArray($meeting->student);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->student));
        $this->assertSame($meeting->id, $data['meeting_id']);
        $this->assertSame('meetings.show', $data['redirect_route']);
        $this->assertInstanceOf(MailMessage::class, $notification->toMail($meeting->student));
        $this->assertStringContainsString($meeting->id, $notification->toMail($meeting->student)->actionUrl);
    }

    public function test_canceled_notification_contains_actor_and_meeting_data(): void
    {
        $meeting = Meeting::factory()->canceled()->create();
        $meeting->update(['canceled_by_user_id' => $meeting->student_id]);
        $meeting->load('canceledBy');
        $notification = new MeetingCanceledNotification($meeting);
        $data = $notification->toArray($meeting->coach);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->coach));
        $this->assertSame($meeting->id, $data['meeting_id']);
        $this->assertSame($meeting->student_id, $data['canceled_by_user_id']);
        $this->assertSame($meeting->canceledBy->name, $data['canceled_by_name']);
        $this->assertInstanceOf(MailMessage::class, $notification->toMail($meeting->coach));
    }
}
