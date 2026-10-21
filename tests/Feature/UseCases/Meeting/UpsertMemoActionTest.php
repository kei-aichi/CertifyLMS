<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_memo_for_reserved_meeting(): void
    {
        $meeting = Meeting::factory()->reserved()->create();

        app(UpsertMemoAction::class)($meeting, '予約済み面談のメモ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '予約済み面談のメモ',
        ]);
    }

    public function test_creates_memo_for_completed_meeting(): void
    {
        $meeting = Meeting::factory()->completed()->create();

        app(UpsertMemoAction::class)($meeting, '完了面談のメモ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '完了面談のメモ',
        ]);
    }

    public function test_updates_existing_memo_without_creating_a_duplicate(): void
    {
        $meeting = Meeting::factory()->completed()->create();
        $memo = MeetingMemo::factory()->forMeeting($meeting)->create(['body' => '旧メモ']);
        $otherMeeting = Meeting::factory()->completed()->create();
        MeetingMemo::factory()->forMeeting($otherMeeting)->create(['body' => '別面談のメモ']);

        app(UpsertMemoAction::class)($meeting, '更新後メモ');

        $this->assertDatabaseHas('meeting_memos', [
            'id' => $memo->id,
            'meeting_id' => $meeting->id,
            'body' => '更新後メモ',
        ]);
        $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $otherMeeting->id,
            'body' => '別面談のメモ',
        ]);
    }

    public function test_rejects_canceled_meeting_without_creating_or_updating_memo(): void
    {
        $meeting = Meeting::factory()->canceled()->create();

        try {
            app(UpsertMemoAction::class)($meeting, '保存されないメモ');
            $this->fail('Expected MeetingStatusTransitionException was not thrown.');
        } catch (MeetingStatusTransitionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('meeting_memos', [
            'meeting_id' => $meeting->id,
        ]);
    }
}
