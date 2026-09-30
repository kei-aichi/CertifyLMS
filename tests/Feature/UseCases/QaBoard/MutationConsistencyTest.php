<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaBoard;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\UnresolveAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 受付後にデータが変わった場合も、古いModelの認可・状態を信用しないことを保証する。
 * 並列プロセスの再現ではなく、競合の両順序とMySQLの親行ロックを処理単位で検証する。
 */
class MutationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_committed_after_initial_authorization_prevents_author_deletion(): void
    {
        $thread = QaThread::factory()->create();
        $user = $thread->user;
        $this->assertTrue($user->can('delete', $thread));
        app(StoreAction::class)($thread, $user, ['body' => '回答']);
        try {
            app(DestroyAction::class)($thread, $user);
            $this->fail('回答追加後の本人削除を許可してはいけない');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
            $this->assertSame(1, $thread->replies()->count());
        }
    }

    public function test_deletion_first_prevents_reply_creation_from_stale_model(): void
    {
        $thread = QaThread::factory()->create();
        $user = $thread->user;
        app(DestroyAction::class)($thread, $user);
        try {
            app(StoreAction::class)($thread, $user, ['body' => '回答']);
            $this->fail('削除済み質問へ回答を追加してはいけない');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, QaReply::count());
        }
    }

    public function test_state_operations_reload_before_idempotent_success(): void
    {
        foreach (['open' => ResolveAction::class, 'resolved' => UnresolveAction::class] as $state => $action) {
            $thread = QaThread::factory()->{$state}()->create();
            $user = $thread->user;
            $result = app($action)($thread, $user);
            $before = $result->fresh()->getAttributes();
            $this->travel(1)->hours();
            // 古いModelを再送してもDBの最新状態を使い、解決日時・更新日時を上書きしない。
            $retried = app($action)($thread, $user);
            $this->assertSame($before, $retried->getAttributes());
            $this->assertSame($before, $thread->fresh()->getAttributes());
            // Actionを直接呼ぶ場合も、既に目的の状態だからといって認可を省略しない。
            try {
                app($action)($thread, User::factory()->admin()->create());
                $this->fail('代理操作を成功扱いにしてはいけない');
            } catch (AuthorizationException) {
                $this->assertSame($before, $thread->fresh()->getAttributes());
            }
        }
    }

    public function test_reply_creation_and_deletion_lock_the_same_parent_row(): void
    {
        $thread = QaThread::factory()->create();
        $user = $thread->user;
        $admin = User::factory()->admin()->create();
        DB::enableQueryLog();
        try {
            foreach ([fn () => app(StoreAction::class)($thread, $user, ['body' => '回答']),
                fn () => app(DestroyAction::class)($thread, $admin)] as $operation) {
                DB::flushQueryLog();
                $operation();
                $locks = array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'for update')
                    && str_contains($query['query'], 'qa_threads')
                    && in_array($thread->id, $query['bindings'], true));
                $this->assertNotEmpty($locks, '双方が親質問の同一行で排他する必要がある');
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
