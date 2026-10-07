<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Chat;

use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\UseCases\Chat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * StoreMessageAction の責務:
 *
 * - ChatMessage INSERT + 送信者の ChatMember.last_read_at = now() 更新
 * - DB::afterCommit() で ChatMessageSent broadcast を発火
 * - シグネチャは `__invoke(User, ChatRoom, array)`(E-3 撤回後の単一形態)
 */
class StoreMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_message_and_update_sender_last_read_at(): void
    {
        Event::fake([ChatMessageSent::class]);
        Mail::fake();

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        $senderMember = ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'last_read_at' => null,
        ]);
        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);

        $message = app(StoreMessageAction::class)($sender, $room, ['body' => 'こんにちは']);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'chat_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'body' => 'こんにちは',
        ]);
        $this->assertNotNull($senderMember->fresh()->last_read_at);

        Event::assertDispatched(ChatMessageSent::class);
    }

    public function test_notifies_all_in_progress_chat_members_without_notifying_sender(): void
    {
        Event::fake([ChatMessageSent::class]);
        Mail::fake();

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $secondCoach = User::factory()->coach()->inProgress()->create();
        $nonMemberCoach = User::factory()->coach()->inProgress()->create();
        $graduatedCoach = User::factory()->coach()->graduated()->create();
        $invitedCoach = User::factory()->coach()->invited()->create();
        $withdrawnCoach = User::factory()->coach()->withdrawn()->create();
        $room = ChatRoom::factory()->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $sender->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $secondCoach->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $graduatedCoach->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $invitedCoach->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $withdrawnCoach->id]);

        $message = app(StoreMessageAction::class)($sender, $room, ['body' => '通知本文']);

        $notification = DatabaseNotification::query()->where([
            'notifiable_id' => $coach->id,
            'type' => ChatMessageReceivedNotification::class,
        ])->first();
        $this->assertNotNull($notification);
        $this->assertSame($room->id, $notification->data['chat_room_id']);
        $this->assertSame($message->id, $notification->data['chat_message_id']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $secondCoach->id,
            'type' => ChatMessageReceivedNotification::class,
        ]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $nonMemberCoach->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $sender->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $graduatedCoach->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $invitedCoach->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withdrawnCoach->id]);
        $this->assertSame($message->id, $message->fresh()->id);
    }

    public function test_coach_message_notifies_student_by_mail_and_other_coach_without_mail(): void
    {
        Event::fake([ChatMessageSent::class]);
        Notification::fake();

        $sender = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $room = ChatRoom::factory()->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $sender->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $otherCoach->id]);

        app(StoreMessageAction::class)($sender, $room, ['body' => 'コーチからの本文']);

        Notification::assertSentTo($student, ChatMessageReceivedNotification::class, function (ChatMessageReceivedNotification $notification) use ($student): bool {
            return $notification->via($student) === ['database', 'mail'];
        });
        Notification::assertSentTo($otherCoach, ChatMessageReceivedNotification::class, function (ChatMessageReceivedNotification $notification) use ($otherCoach): bool {
            return $notification->via($otherCoach) === ['database'];
        });
        Notification::assertNotSentTo($sender, ChatMessageReceivedNotification::class);
    }

    public function test_signature_is_user_chat_room_array(): void
    {
        $reflection = new \ReflectionMethod(StoreMessageAction::class, '__invoke');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(User::class, $params[0]->getType()?->getName());
        $this->assertSame(ChatRoom::class, $params[1]->getType()?->getName());
        $this->assertSame('array', $params[2]->getType()?->getName());
    }
}
