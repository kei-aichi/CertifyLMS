<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q&A Factory の状態・関連生成を検証する。認可ではなくテストデータ生成の契約を保証する。
 * 未受講投稿や同一ユーザーの複数投稿を、Factory が制限しないことも対象とする。
 */
class QaFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_thread_is_open_on_published_certification_without_enrollment(): void
    {
        $thread = QaThread::factory()->create()->fresh();

        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);
        $this->assertSame(CertificationStatus::Published, $thread->certification->status);
        $this->assertTrue($thread->user->exists);
        $this->assertSame(0, Enrollment::count());
    }

    public function test_states_keep_resolution_timestamp_consistent_without_creating_replies(): void
    {
        $this->freezeTime();
        $resolved = QaThread::factory()->resolved()->create()->fresh();
        $reopened = QaThread::factory()->resolved()->open()->create()->fresh();

        // 自己解決は回答0件でも成立し、open state を重ねた場合は過去の解決日時を消す。
        $this->assertSame(QaThreadStatus::Resolved, $resolved->status);
        $this->assertTrue($resolved->resolved_at->equalTo(now()->startOfSecond()));
        $this->assertSame(0, $resolved->replies()->count());
        $this->assertSame(QaThreadStatus::Open, $reopened->status);
        $this->assertNull($reopened->resolved_at);
    }

    public function test_reply_factory_creates_related_thread_and_author(): void
    {
        $reply = QaReply::factory()->create();

        $this->assertSame($reply->qa_thread_id, $reply->qaThread->id);
        $this->assertSame($reply->user_id, $reply->user->id);
        $this->assertTrue($reply->qaThread->replies->first()->is($reply));
        $this->assertSame(0, Enrollment::count());
    }

    public function test_existing_parents_and_multiple_posts_are_supported(): void
    {
        $certification = Certification::factory()->published()->create();
        $user = User::factory()->student()->create();
        $threads = QaThread::factory()->for($certification)->for($user)->count(2)->create();
        $replies = QaReply::factory()->for($threads->first(), 'qaThread')->for($user)->count(2)->create();

        // 同じ親と投稿者を再利用しても、一意制約やFactoryの副作用で投稿が失われない。
        $this->assertCount(2, $threads);
        $this->assertSame(2, QaThread::where('certification_id', $certification->id)->where('user_id', $user->id)->count());
        $this->assertSame(2, $threads->first()->replies()->where('user_id', $user->id)->count());
        $this->assertTrue($replies->every(fn ($reply) => $reply->qaThread->is($threads->first())));
        $this->assertSame(0, Enrollment::count());
    }

    public function test_factory_allows_explicit_non_public_parent_for_negative_tests(): void
    {
        // 公開資格はデフォルトにすぎず、非公開資格の認可テストを妨げる強制補正はしない。
        $certification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->for($certification)->create();

        $this->assertTrue($thread->certification->is($certification));
        $this->assertSame(CertificationStatus::Draft, $thread->certification->status);
    }
}
