<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaBoard;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Q&AそのもののFKによる保持と、正式な退会HTTP経路・ダッシュボード接続を保証する。 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_certification_foreign_key_preserves_questions_and_replies(): void
    {
        $cert = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->for($cert)->create();
        $reply = QaReply::factory()->for($thread, 'qaThread')->create();
        $this->assertSame(0, Enrollment::withTrashed()->count());
        $this->assertForeignKeyRejects(fn () => $cert->delete(), 'qa_threads_certification_id_foreign');
        $this->assertDatabaseHas('certifications', ['id' => $cert->id]);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_question_author_cannot_be_physically_deleted(): void
    {
        // 資格の作成者とは別のUserを使い、Q&A以外の参照で偶然拒否される構成を避ける。
        $thread = QaThread::factory()->create();
        $author = $thread->user;
        $this->assertSame(0, Enrollment::withTrashed()->count());
        $this->assertSame(0, QaReply::where('user_id', $author->id)->count());
        $this->assertForeignKeyRejects(fn () => $author->forceDelete(), 'qa_threads_user_id_foreign');
        $this->assertDatabaseHas('users', ['id' => $author->id]);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_reply_only_author_cannot_be_physically_deleted(): void
    {
        $reply = QaReply::factory()->create();
        $author = $reply->user;
        $this->assertSame(0, QaThread::where('user_id', $author->id)->count());
        $this->assertSame(0, Enrollment::withTrashed()->count());
        $this->assertForeignKeyRejects(fn () => $author->forceDelete(), 'qa_replies_user_id_foreign');
        $this->assertDatabaseHas('users', ['id' => $author->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_withdrawal_preserves_posts_and_dashboard_loads_original_author(): void
    {
        $thread = QaThread::factory()->create();
        $author = $thread->user;
        $name = $author->name;
        // 未回答質問はサマリ対象に残し、同じ投稿者の回答保持は別質問で確認する。
        $reply = QaReply::factory()->for($author)->create();
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id]);
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.withdraw', $author))->assertRedirect();

        $withdrawn = User::withTrashed()->findOrFail($author->id);
        $this->assertSame(UserStatus::Withdrawn, $withdrawn->status);
        $this->assertSoftDeleted($withdrawn);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id, 'user_id' => $author->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'user_id' => $author->id]);

        $response = $this->actingAs($coach)->get(route('dashboard.index'))->assertOk()->assertSee($name);
        $model = $response->viewData('viewModel');
        $this->assertSame(1, $model->unansweredQaCount);
        $loaded = $model->recentQaThreads->sole();
        $this->assertSame($thread->id, $loaded->id);
        $this->assertTrue($loaded->relationLoaded('user'));
        $this->assertNotNull($loaded->user);
        $this->assertSame($author->id, $loaded->user->id);
        $this->assertSame($name, $loaded->user->name);
        $this->assertTrue($loaded->user->trashed());
    }

    private function assertForeignKeyRejects(callable $delete, string $constraint): void
    {
        try {
            $delete();
            $this->fail('Q&Aの参照制約で物理削除を拒否する必要がある');
        } catch (QueryException $exception) {
            // 単に例外が出るだけでなく、MySQLの該当Q&A制約による拒否であることを確認する。
            $this->assertSame(1451, (int) $exception->errorInfo[1]);
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
