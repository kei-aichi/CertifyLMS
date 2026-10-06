<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 状態変更は3遷移のみ許可し、二重操作でも監査情報を変更しない。
 */
class TransitionTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            ['publish', MeetingPackStatus::Draft, MeetingPackStatus::Published, '面談パックを公開しました。'],
            ['archive', MeetingPackStatus::Published, MeetingPackStatus::Archived, '面談パックをアーカイブしました。'],
            ['unarchive', MeetingPackStatus::Archived, MeetingPackStatus::Draft, '面談パックを下書きへ戻しました。'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transition_succeeds_and_repeat_is_rejected(string $operation, MeetingPackStatus $from, MeetingPackStatus $to, string $message): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->create(['status' => $from]);
        $creator = $plan->created_by_user_id;
        $url = route('admin.meeting-packs.'.$operation, $plan);
        $this->actingAs($admin)->post($url)
            ->assertRedirect(route('admin.meeting-packs.show', $plan))
            ->assertSessionHas('success', $message);
        $plan->refresh();
        $this->assertSame($to, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
        $this->assertSame($creator, $plan->created_by_user_id);

        // 時刻と操作管理者を変えて再送し、拒否時に更新者・更新日時も保持されることを確認する。
        $before = $plan->getRawOriginal();
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->admin()->create())->postJson($url)->assertConflict();
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public static function invalidTransitions(): array
    {
        return [
            ['publish', MeetingPackStatus::Published, '下書き状態の面談パックのみ公開できます。'],
            ['publish', MeetingPackStatus::Archived, '下書き状態の面談パックのみ公開できます。'],
            ['archive', MeetingPackStatus::Draft, '公開中の面談パックのみアーカイブできます。'],
            ['archive', MeetingPackStatus::Archived, '公開中の面談パックのみアーカイブできます。'],
            ['unarchive', MeetingPackStatus::Draft, 'アーカイブ状態の面談パックのみ下書きへ戻せます。'],
            ['unarchive', MeetingPackStatus::Published, 'アーカイブ状態の面談パックのみ下書きへ戻せます。'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_preserves_data_for_html_and_json(string $operation, MeetingPackStatus $status, string $message): void
    {
        $plan = MeetingPack::factory()->create(['status' => $status]);
        $before = $plan->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->admin()->create());
        $url = route('admin.meeting-packs.'.$operation, $plan);
        $back = route('admin.meeting-packs.show', $plan);

        // 同じ業務例外を、既存Handlerがリクエスト形式に応じて変換する。
        $this->from($back)->post($url)->assertRedirect($back)->assertSessionHas('error', $message);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->postJson($url)->assertConflict()->assertJsonPath('message', $message);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }
}
