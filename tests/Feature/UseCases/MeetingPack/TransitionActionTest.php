<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\ArchiveAction;
use App\UseCases\MeetingPack\PublishAction;
use App\UseCases\MeetingPack\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 呼び出し元の古いModelではなく、ロックして読み直したDB状態で遷移を判断する。
 */
class TransitionActionTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            [PublishAction::class, MeetingPackStatus::Draft, MeetingPackStatus::Published],
            [ArchiveAction::class, MeetingPackStatus::Published, MeetingPackStatus::Archived],
            [UnarchiveAction::class, MeetingPackStatus::Archived, MeetingPackStatus::Draft],
        ];
    }

    #[DataProvider('transitions')]
    public function test_latest_db_state_allows_transition_despite_stale_model(string $actionClass, MeetingPackStatus $from, MeetingPackStatus $to): void
    {
        $admin = User::factory()->admin()->create();
        $stale = MeetingPack::factory()->create(['status' => $to]);
        MeetingPack::whereKey($stale->id)->update(['status' => $from->value]);
        $result = (new $actionClass)($stale, $admin);
        $this->assertSame($to, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
        $this->assertSame($stale->created_by_user_id, $result->created_by_user_id);
    }

    #[DataProvider('transitions')]
    public function test_latest_db_state_rejects_transition_and_rolls_back_without_changes(string $actionClass, MeetingPackStatus $from, MeetingPackStatus $to): void
    {
        $admin = User::factory()->admin()->create();
        $stale = MeetingPack::factory()->create(['status' => $from]);
        // 他のリクエストが先に状態変更を完了した状況を再現する。
        MeetingPack::whereKey($stale->id)->update(['status' => $to->value]);
        $before = $stale->fresh()->getRawOriginal();
        $level = DB::transactionLevel();
        $this->travel(1)->minutes();
        try {
            (new $actionClass)($stale, $admin);
            $this->fail('最新状態で遷移済みの操作は拒否される必要があります。');
        } catch (MeetingPackInvalidTransitionException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($before, $stale->fresh()->getRawOriginal());
        $this->assertSame($level, DB::transactionLevel());
    }
}
