<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\NotificationResource;
use App\Models\Announcement;
use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class NotificationResourceTest extends TestCase
{
    /** @dataProvider previewData */
    public function test_preview_uses_the_existing_notification_fallback_order(array $data, string $expected): void
    {
        $notification = new DatabaseNotification([
            'id' => 'notification-id',
            'type' => 'test',
            'data' => $data,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        $result = (new NotificationResource($notification))->toArray(Request::create('/api/v1/notifications'));

        self::assertSame($expected, $result['preview']);
        self::assertTrue($result['is_unread']);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function previewData(): array
    {
        return [
            'message' => [['message' => 'message', 'body_preview' => 'preview', 'body' => 'body'], 'message'],
            'body_preview' => [['body_preview' => 'preview', 'body' => 'body'], 'preview'],
            'body' => [['body' => 'body'], 'body'],
            'empty' => [[], ''],
        ];
    }

    public function test_arbitrary_redirect_url_falls_back_to_notifications_index(): void
    {
        $notification = new DatabaseNotification([
            'id' => 'notification-id',
            'type' => 'test',
            'data' => [
                'redirect_url' => 'https://example.test/unsafe',
                'redirect_route' => 'invalid.route',
            ],
        ]);

        $result = (new NotificationResource($notification))->toArray(Request::create('/api/v1/notifications'));

        self::assertSame(route('notifications.index'), $result['redirect_url']);
    }

    public function test_allowed_redirect_routes_generate_internal_urls(): void
    {
        $cases = [
            ['chat.show', ['room' => 'room-id']],
            ['qa-board.show', ['thread' => 'thread-id']],
            ['meetings.show', ['meeting' => 'meeting-id']],
            ['notifications.show', ['ignored' => 'value']],
        ];

        foreach ($cases as [$routeName, $parameters]) {
            $notification = new DatabaseNotification([
                'id' => 'notification-id',
                'type' => 'test',
                'data' => [
                    'redirect_route' => $routeName,
                    'redirect_parameters' => $parameters,
                ],
            ]);

            $result = (new NotificationResource($notification))->toArray(
                Request::create('/api/v1/notifications'),
            );

            $expected = $routeName === 'notifications.show'
                ? route($routeName, ['notification' => 'notification-id'])
                : route($routeName, $parameters);

            self::assertSame($expected, $result['redirect_url']);
            self::assertStringStartsWith(config('app.url'), $result['redirect_url']);
        }
    }

    public function test_invalid_redirect_parameters_fall_back_to_notifications_index(): void
    {
        $notification = new DatabaseNotification([
            'id' => 'notification-id',
            'type' => 'test',
            'data' => [
                'redirect_route' => 'chat.show',
                'redirect_parameters' => 'room-id',
            ],
        ]);

        $result = (new NotificationResource($notification))->toArray(Request::create('/api/v1/notifications'));

        self::assertSame(route('notifications.index'), $result['redirect_url']);
    }

    public function test_read_and_unread_fields_are_serialized_consistently(): void
    {
        foreach ([null, Carbon::parse('2026-01-01 00:05:00')] as $readAt) {
            $notification = new DatabaseNotification([
                'id' => 'notification-id',
                'type' => 'test',
                'data' => ['message' => '本文'],
                'created_at' => Carbon::parse('2026-01-01 00:00:00'),
                'read_at' => $readAt,
            ]);

            $result = (new NotificationResource($notification))->toArray(
                Request::create('/api/v1/notifications'),
            );

            self::assertIsString($result['created_at']);
            self::assertIsString($result['relative_time']);
            self::assertSame($readAt?->toIso8601String(), $result['read_at']);
            self::assertSame($readAt === null, $result['is_unread']);
        }
    }

    public function test_all_existing_notification_classes_produce_resource_data(): void
    {
        $user = User::factory()->inProgress()->make(['id' => 'user-id', 'name' => '受信者']);
        $sender = User::factory()->coach()->make(['id' => 'sender-id', 'name' => '送信者']);
        $chatMessage = new ChatMessage([
            'id' => 'chat-message-id',
            'body' => 'チャット本文',
            'chat_room_id' => 'room-id',
            'sender_user_id' => 'sender-id',
        ]);
        $chatMessage->setRelation('sender', $sender);
        $chatMessage->setRelation('chatRoom', null);

        $reply = new QaReply([
            'id' => 'reply-id',
            'body' => 'Q&A本文',
            'qa_thread_id' => 'thread-id',
            'user_id' => 'sender-id',
        ]);
        $reply->setRelation('qaThread', null);
        $reply->setRelation('user', $sender);

        $meeting = new Meeting([
            'id' => 'meeting-id',
            'scheduled_at' => Carbon::parse('2026-01-02 10:00:00'),
            'coach_id' => 'coach-id',
            'student_id' => 'student-id',
        ]);
        $meeting->setRelation('coach', $sender);
        $meeting->setRelation('student', $user);
        $meeting->setRelation('canceledBy', $sender);

        $announcement = new Announcement([
            'id' => 'announcement-id',
            'title' => '運営のお知らせ',
            'body' => 'お知らせ本文',
        ]);

        $notifications = [
            ['notification' => new ChatMessageReceivedNotification($chatMessage), 'type' => 'chat_message_received'],
            ['notification' => new QaReplyReceivedNotification($reply), 'type' => 'qa_reply_received'],
            ['notification' => new MeetingReservedNotification($meeting), 'type' => 'meeting_reserved'],
            ['notification' => new MeetingCanceledNotification($meeting), 'type' => 'meeting_canceled'],
            ['notification' => new AnnouncementNotification($announcement), 'type' => 'admin_announcement'],
            ['notification' => new MeetingReminderNotification($meeting, 'eve'), 'type' => 'meeting_reminder'],
        ];

        foreach ($notifications as $case) {
            $notification = $case['notification'];
            $notificationModel = new DatabaseNotification([
                'id' => 'notification-'.strtolower(class_basename($notification)),
                'type' => $notification::class,
                'data' => $notification->toArray($user),
                'created_at' => Carbon::parse('2026-01-01 00:00:00'),
                'read_at' => null,
            ]);
            $result = (new NotificationResource($notificationModel))->toArray(Request::create('/api/v1/notifications'));

            self::assertSame([
                'id', 'type', 'notification_type', 'title', 'preview', 'created_at',
                'relative_time', 'read_at', 'is_unread', 'redirect_url',
            ], array_keys($result));
            self::assertIsString($result['id']);
            self::assertIsString($result['type']);
            self::assertSame($case['type'], $result['notification_type']);
            self::assertIsString($result['title']);
            self::assertIsString($result['preview']);
            self::assertIsString($result['created_at']);
            self::assertIsString($result['relative_time']);
            self::assertNull($result['read_at']);
            self::assertTrue($result['is_unread']);
            self::assertIsString($result['redirect_url']);
            self::assertStringStartsWith(config('app.url'), $result['redirect_url']);
        }
    }
}
