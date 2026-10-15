<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatUnreadCountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_count_in_room_excludes_own_and_pre_read(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);

        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $coach->id,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $coach->id,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
        ]);

        $count = app(ChatUnreadCountService::class)->messageCountInRoom($room, $student);

        $this->assertSame(2, $count);
    }

    public function test_room_count_for_user_counts_only_rooms_with_unread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollmentA = Enrollment::factory()->for($student)->create();
        $roomA = ChatRoom::factory()->for($enrollmentA)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomA->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $roomA->id,
            'sender_user_id' => $coach->id,
        ]);

        $enrollmentB = Enrollment::factory()->for($student)->create();
        $roomB = ChatRoom::factory()->for($enrollmentB)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomB->id,
            'user_id' => $student->id,
            'last_read_at' => now(),
        ]);

        $count = app(ChatUnreadCountService::class)->roomCountForUser($student);

        $this->assertSame(1, $count);
    }

    public function test_message_counts_by_room_returns_keyed_array_with_own_and_pre_read_excluded(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollmentA = Enrollment::factory()->for($student)->create();
        $roomA = ChatRoom::factory()->for($enrollmentA)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomA->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        // roomA に未読 2 件 + 自分送信 1 件
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $coach->id]);
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $coach->id]);
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $student->id]);

        $enrollmentB = Enrollment::factory()->for($student)->create();
        $roomB = ChatRoom::factory()->for($enrollmentB)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $roomB->id,
            'user_id' => $student->id,
            'last_read_at' => now(),
        ]);
        // roomB は last_read_at = now() なので、それより前のメッセージは既読扱い
        ChatMessage::factory()->create([
            'chat_room_id' => $roomB->id,
            'sender_user_id' => $coach->id,
            'created_at' => now()->subMinutes(5),
        ]);

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([$roomA, $roomB]),
            $student,
        );

        $this->assertSame(2, $counts[$roomA->id]);
        $this->assertSame(0, $counts[$roomB->id]);
    }

    public function test_message_counts_by_room_returns_zero_for_non_member_room(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        // student は ChatMember として参加していない
        ChatMessage::factory()->create(['chat_room_id' => $room->id, 'sender_user_id' => $coach->id]);

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([$room]),
            $student,
        );

        $this->assertSame(0, $counts[$room->id]);
    }

    public function test_message_counts_by_room_returns_empty_array_for_empty_input(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $counts = app(ChatUnreadCountService::class)->messageCountsByRoomForUser(
            collect([]),
            $student,
        );

        $this->assertSame([], $counts);
    }

    public function test_all_unread_methods_return_zero_for_room_with_only_own_messages(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
        ]);

        $service = app(ChatUnreadCountService::class);

        $this->assertSame(0, $service->messageCountInRoom($room, $student));
        $this->assertSame(0, $service->messageCountsByRoomForUser(collect([$room]), $student)[$room->id]);
        $this->assertSame(0, $service->roomCountForUser($student));
    }

    public function test_message_count_in_room_applies_created_at_boundaries_and_null_last_read(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        $base = CarbonImmutable::parse('2026-01-01 12:00:00');
        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $student->id,
            'last_read_at' => $base,
        ]);

        foreach ([$base->subMinute(), $base, $base->addMinute()] as $createdAt) {
            ChatMessage::factory()->create([
                'chat_room_id' => $room->id,
                'sender_user_id' => $coach->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
        ChatMessage::factory()->create([
            'chat_room_id' => $room->id,
            'sender_user_id' => $student->id,
            'created_at' => $base->addMinute(),
            'updated_at' => $base->addMinute(),
        ]);

        $service = app(ChatUnreadCountService::class);
        $this->assertSame(1, $service->messageCountInRoom($room, $student));

        ChatMember::query()
            ->where('chat_room_id', $room->id)
            ->where('user_id', $student->id)
            ->update(['last_read_at' => null]);

        $this->assertSame(3, $service->messageCountInRoom($room, $student));
    }

    public function test_multiple_room_counts_exclude_own_only_and_read_rooms(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $base = CarbonImmutable::parse('2026-01-01 12:00:00');

        $ownOnlyRoom = ChatRoom::factory()->for(Enrollment::factory()->for($student))->create();
        ChatMember::factory()->create([
            'chat_room_id' => $ownOnlyRoom->id,
            'user_id' => $student->id,
            'last_read_at' => null,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $ownOnlyRoom->id,
            'sender_user_id' => $student->id,
            'created_at' => $base->addMinute(),
            'updated_at' => $base->addMinute(),
        ]);

        $unreadRoom = ChatRoom::factory()->for(Enrollment::factory()->for($student))->create();
        ChatMember::factory()->create([
            'chat_room_id' => $unreadRoom->id,
            'user_id' => $student->id,
            'last_read_at' => $base,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $unreadRoom->id,
            'sender_user_id' => $coach->id,
            'created_at' => $base->addSecond(),
            'updated_at' => $base->addSecond(),
        ]);

        $readRoom = ChatRoom::factory()->for(Enrollment::factory()->for($student))->create();
        ChatMember::factory()->create([
            'chat_room_id' => $readRoom->id,
            'user_id' => $student->id,
            'last_read_at' => $base,
        ]);
        ChatMessage::factory()->create([
            'chat_room_id' => $readRoom->id,
            'sender_user_id' => $coach->id,
            'created_at' => $base->subMinute(),
            'updated_at' => $base->subMinute(),
        ]);

        $service = app(ChatUnreadCountService::class);
        $counts = $service->messageCountsByRoomForUser(
            collect([$ownOnlyRoom, $unreadRoom, $readRoom]),
            $student,
        );

        $this->assertSame(0, $counts[$ownOnlyRoom->id]);
        $this->assertSame(1, $counts[$unreadRoom->id]);
        $this->assertSame(0, $counts[$readRoom->id]);
        $this->assertSame(1, $service->roomCountForUser($student));
    }
}
