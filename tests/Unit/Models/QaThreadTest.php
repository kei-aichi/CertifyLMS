<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 質問・回答の関連、ULID、解決状態の cast と永続化を検証する。
 * 回答操作による親質問の不変性、投稿者の論理削除後の投稿保持、質問の物理削除も対象とする。
 * ロール別認可・状態遷移時の409はここでは扱わず、Policy / Action のテストで保証する。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_generates_ulid_and_loads_default_state_and_parents(): void
    {
        $thread = $this->createThread();

        $this->assertTrue(Str::isUlid($thread->id));
        // 状態を指定せず保存した質問は、DBの既定値で未解決・解決日時なしとなる。
        $this->assertSame(QaThreadStatus::Open, $thread->fresh()->status);
        $this->assertNull($thread->fresh()->resolved_at);
        $this->assertSame($thread->certification_id, $thread->certification->id);
        $this->assertSame($thread->user_id, $thread->user->id);
    }

    public function test_resolved_state_and_datetime_round_trip(): void
    {
        // 状態遷移の認可ではなく、解決状態と日時を保存・再取得した際の型変換を確認する。
        $thread = $this->createThread();
        $thread->update(['status' => QaThreadStatus::Resolved, 'resolved_at' => '2026-09-24 10:00:00']);
        $fresh = $thread->fresh();

        $this->assertSame(QaThreadStatus::Resolved, $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->resolved_at);
        $this->assertSame('2026-09-24 10:00:00', $fresh->resolved_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id, 'status' => 'resolved']);
    }

    public function test_replies_relations_and_count_are_scoped_to_the_thread(): void
    {
        $thread = $this->createThread();
        $other = $this->createThread();
        $author = User::factory()->student()->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '回答']);
        $other->replies()->create(['user_id' => $author->id, 'body' => '別の質問への回答']);

        $this->assertTrue(Str::isUlid($reply->id));
        $this->assertTrue($reply->qaThread->is($thread));
        $this->assertTrue($reply->user->is($author));
        // 他の質問にも同じ回答者が投稿していても、回答一覧・件数は親質問単位に分離される。
        $this->assertCount(1, $thread->replies);
        $this->assertTrue($thread->replies->first()->is($reply));
        $this->assertSame(1, QaThread::withCount('replies')->findOrFail($thread->id)->replies_count);
    }

    public function test_reply_changes_do_not_touch_thread_state_or_timestamps(): void
    {
        $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));
        $thread = $this->createThread();
        $thread->update(['status' => QaThreadStatus::Resolved, 'resolved_at' => now()]);
        $original = $thread->fresh()->getAttributes();
        // 同時刻の保存で意図しない親の更新を見逃さないよう、回答操作まで時刻を進める。
        $this->travel(1)->hour();

        $reply = $thread->replies()->create(['user_id' => $thread->user_id, 'body' => '回答']);
        $this->assertSame($original, $thread->fresh()->getAttributes());
        $this->travel(1)->hour();

        // 回答自身の更新日時は進むが、質問の解決状態・解決日時・更新日時には影響しない。
        $reply->update(['body' => '編集後']);
        $this->assertSame('編集後', $reply->fresh()->body);
        $this->assertTrue($reply->fresh()->updated_at->greaterThan($reply->created_at));
        $this->assertSame($original, $thread->fresh()->getAttributes());

        // 最後の回答を削除しても、自己解決した質問として解決状態を維持する。
        $reply->delete();
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        $this->assertSame($original, $thread->fresh()->getAttributes());
        $this->travelBack();
    }

    public function test_soft_deleting_author_preserves_posts_and_thread_delete_cascades(): void
    {
        $thread = $this->createThread();
        $reply = $thread->replies()->create(['user_id' => $thread->user_id, 'body' => '回答']);
        // 退会処理のうち SoftDelete が投稿を消さないことを検証する。退会状態への遷移は対象外。
        $thread->user->delete();

        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);

        // 質問そのものの削除では回答も物理削除される。操作者の削除可否はPolicy側の責務。
        $thread->delete();

        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    private function createThread(): QaThread
    {
        return QaThread::create([
            'certification_id' => Certification::factory()->published()->create()->id,
            'user_id' => User::factory()->student()->create()->id,
            'title' => '質問タイトル',
            'body' => '質問本文',
        ]);
    }
}
