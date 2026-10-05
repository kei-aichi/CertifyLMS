<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 削除は状態のみで判断し、公開中の拒否では監査情報も保持する。 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public static function deletableStatuses(): array
    {
        return [[MeetingPackStatus::Draft], [MeetingPackStatus::Archived]];
    }

    #[DataProvider('deletableStatuses')]
    public function test_admin_physically_deletes_pack(MeetingPackStatus $status): void
    {
        $plan = MeetingPack::factory()->create(['status' => $status]);
        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.meeting-packs.destroy', $plan))
            ->assertRedirect(route('admin.meeting-packs.index'))
            ->assertSessionHas('success', '面談パックを削除しました。');
        $this->assertDatabaseMissing('meeting_packs', ['id' => $plan->id]);
    }

    public function test_published_pack_is_preserved_for_html_and_json_rejections(): void
    {
        $plan = MeetingPack::factory()->published()->create();
        $before = $plan->fresh()->getRawOriginal();
        // 拒否時に更新日時・操作管理者まで書き換えていないことを検証する。
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->admin()->create());
        $back = route('admin.meeting-packs.show', $plan);
        $url = route('admin.meeting-packs.destroy', $plan);
        $this->from($back)->delete($url)->assertRedirect($back)
            ->assertSessionHas('error', '公開中の面談パックは削除できません。');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->deleteJson($url)->assertConflict()
            ->assertJsonPath('message', '公開中の面談パックは削除できません。');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }
}
