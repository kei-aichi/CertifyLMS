<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 通知一覧確認用のDatabase Notificationを投入する。
 * 業務イベントを発生させず、既存Seederが作成した業務データだけを参照する。
 */
final class NotificationSeeder extends Seeder
{
    private const NOTIFICATION_COUNT = 6;

    public function run(): void
    {
        $records = [
            ...$this->chatNotifications(),
            ...$this->qaNotifications(),
            ...$this->reservedMeetingNotifications(),
            ...$this->canceledMeetingNotifications(),
        ];

        if (count($records) !== 24) {
            $this->command?->warn('NotificationSeeder: 必要な既存業務データが不足しています。');

            return;
        }

        DB::transaction(function () use ($records): void {
            foreach ($records as $record) {
                DatabaseNotification::query()->updateOrCreate(
                    ['id' => $record['id']],
                    $record['attributes'],
                );
            }
        });
    }

    /** @return list<array{id: string, attributes: array<string, mixed>}> */
    private function chatNotifications(): array
    {
        $messages = ChatMessage::query()->with(['sender', 'chatRoom.members.user'])->oldest()->get();
        $records = [];

        $messages = $messages->filter(fn (ChatMessage $message): bool => $message->chatRoom->members
            ->contains(fn (ChatMember $member): bool => $member->user_id !== $message->sender_user_id));
        foreach ($this->cycle($messages) as $index => $message) {
            $recipient = $message->chatRoom->members
                ->first(fn (ChatMember $member): bool => $member->user_id !== $message->sender_user_id)
                ?->user;
            if ($recipient === null) {
                continue;
            }

            $records[] = $this->record(
                'chat', $index, $recipient, new ChatMessageReceivedNotification($message), now()->subDays(1)->subHours($index),
            );
        }

        return $records;
    }

    /** @return list<array{id: string, attributes: array<string, mixed>}> */
    private function qaNotifications(): array
    {
        $replies = QaReply::query()->with(['qaThread.user', 'user'])->oldest()->get();
        $records = [];

        $replies = $replies->filter(fn (QaReply $reply): bool => $reply->qaThread->user !== null
            && $reply->qaThread->user->id !== $reply->user_id);
        foreach ($this->cycle($replies) as $index => $reply) {
            $recipient = $reply->qaThread->user;
            if ($recipient === null || $recipient->id === $reply->user_id) {
                continue;
            }

            $records[] = $this->record(
                'qa', $index, $recipient, new QaReplyReceivedNotification($reply), now()->subDays(2)->subHours($index),
            );
        }

        return $records;
    }

    /** @return list<array{id: string, attributes: array<string, mixed>}> */
    private function reservedMeetingNotifications(): array
    {
        $meetings = Meeting::query()->where('status', MeetingStatus::Reserved)->with(['coach', 'student'])->oldest()->get();

        $meetings = $meetings->filter(fn (Meeting $meeting): bool => $meeting->coach !== null);
        $records = [];
        foreach ($this->cycle($meetings) as $index => $meeting) {

            $records[] = $this->record(
                'reserved', $index, $meeting->coach, new MeetingReservedNotification($meeting), now()->subDays(3)->subHours($index),
            );
        }

        return $records;
    }

    /** @return list<array{id: string, attributes: array<string, mixed>}> */
    private function canceledMeetingNotifications(): array
    {
        $meetings = Meeting::query()->where('status', MeetingStatus::Canceled)->with(['coach', 'student', 'canceledBy'])->oldest()->get();
        $records = [];

        $meetings = $meetings->filter(function (Meeting $meeting): bool {
            $actorId = $meeting->canceled_by_user_id;

            return $actorId !== null && ($meeting->student_id === $actorId ? $meeting->coach : $meeting->student) !== null;
        });
        $records = [];
        foreach ($this->cycle($meetings) as $index => $meeting) {
            $actorId = $meeting->canceled_by_user_id;
            $recipient = $meeting->student_id === $actorId ? $meeting->coach : $meeting->student;

            $records[] = $this->record(
                'canceled', $index, $recipient, new MeetingCanceledNotification($meeting), now()->subDays(4)->subHours($index),
            );
        }

        return $records;
    }

    /** @param iterable<Model> $models */
    private function cycle(iterable $models): iterable
    {
        $models = collect($models)->values();
        if ($models->isEmpty()) {
            return [];
        }

        return collect(range(0, self::NOTIFICATION_COUNT - 1))->map(
            fn (int $index): Model => $models->get($index % $models->count()),
        );
    }

    /** @return array{id: string, attributes: array<string, mixed>} */
    private function record(
        string $kind,
        int $index,
        User $recipient,
        object $notification,
        Carbon $createdAt,
    ): array {
        $data = $notification->toArray($recipient);
        $kindNumber = match ($kind) {
            'chat' => 1,
            'qa' => 2,
            'reserved' => 3,
            default => 4,
        };
        $id = sprintf('00000000-0000-4000-8000-%012d', $kindNumber * 1000000 + $index + 1);

        return [
            'id' => $id,
            'attributes' => [
                'type' => $notification::class,
                'notifiable_type' => $recipient::class,
                'notifiable_id' => $recipient->id,
                'data' => $data,
                'read_at' => $index % 2 === 0 ? null : $createdAt->copy()->addMinutes(10),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        ];
    }
}
