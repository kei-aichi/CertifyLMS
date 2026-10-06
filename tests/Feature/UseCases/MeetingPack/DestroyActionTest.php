<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Binding後の状態変更を想定し、最新DB状態に基づく削除を保証する。 */
class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_draft_cannot_delete_currently_published_pack(): void
    {
        $stale = MeetingPack::factory()->draft()->create();
        MeetingPack::whereKey($stale->id)->update(['status' => MeetingPackStatus::Published->value]);
        $before = $stale->fresh()->getRawOriginal();
        $level = DB::transactionLevel();
        $this->travel(1)->minutes();
        try {
            (new DestroyAction)($stale);
            $this->fail('DB上で公開済みのパックは削除できません。');
        } catch (MeetingPackNotDeletableException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($before, $stale->fresh()->getRawOriginal());
        $this->assertSame($level, DB::transactionLevel());
    }

    public static function deletableStatuses(): array
    {
        return [[MeetingPackStatus::Draft], [MeetingPackStatus::Archived]];
    }

    #[DataProvider('deletableStatuses')]
    public function test_stale_published_model_can_be_deleted_when_latest_state_allows(MeetingPackStatus $status): void
    {
        $stale = MeetingPack::factory()->published()->create();
        MeetingPack::whereKey($stale->id)->update(['status' => $status->value]);
        (new DestroyAction)($stale);
        $this->assertDatabaseMissing('meeting_packs', ['id' => $stale->id]);
    }
}
