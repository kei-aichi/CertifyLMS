<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** GET経由でSQLの公開範囲とPolicyの直接アクセス制限、提供済みBladeへの接続を保証する。 */
class ReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_admin_lists_follow_publication_without_enrollment(): void
    {
        $threads = collect(CertificationStatus::cases())->map(fn ($status) => QaThread::factory()
            ->for(Certification::factory()->state(['status' => $status]))->create());
        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.index'))->assertOk()
            ->assertViewHas('threads', fn ($rows) => $rows->pluck('id')->all() === [$threads[1]->id])
            ->assertViewHas('certifications', fn ($rows) => $rows->pluck('id')->all() === [$threads[1]->certification_id]);
        $this->assertSame(0, Enrollment::count());
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.qa-board.index'))->assertOk()
            ->assertViewHas('threads', fn ($rows) => $rows->total() === 3);
        foreach ($threads as $thread) {
            $this->get(route('admin.qa-board.show', $thread))->assertOk();
        }
    }

    public function test_coach_only_sees_current_published_assignments(): void
    {
        $coach = User::factory()->coach()->create();
        $visible = QaThread::factory()->create();
        $removed = QaThread::factory()->create();
        $draft = QaThread::factory()->for(Certification::factory()->draft())->create();
        $outside = QaThread::factory()->create();
        foreach ([$visible, $removed, $draft] as $thread) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id,
                'unassigned_at' => $thread->is($removed) ? now() : null]);
        }
        $this->actingAs($coach)->get(route('qa-board.index'))->assertOk()
            ->assertViewHas('threads', fn ($rows) => $rows->pluck('id')->all() === [$visible->id]);
        $this->get(route('qa-board.show', $visible))->assertOk();
        foreach ([$removed, $draft, $outside] as $thread) {
            $this->get(route('qa-board.show', $thread))->assertForbidden();
            $this->get(route('qa-board.index', ['certification_id' => $thread->certification_id]))->assertOk()
                ->assertViewHas('threads', fn ($rows) => $rows->isEmpty());
        }
    }

    public function test_get_authorization_and_create_options(): void
    {
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($thread, 'qaThread')->for($thread->user)->create();
        foreach (['index' => [], 'create' => [], 'show' => [$thread], 'edit' => [$thread], 'replies.edit' => [$thread, $reply]] as $name => $args) {
            $this->getJson(route('qa-board.'.$name, $args))->assertUnauthorized();
        }
        Certification::factory()->draft()->create();
        $this->actingAs($thread->user)->get(route('qa-board.create'))->assertOk()
            ->assertViewHas('certifications', fn ($rows) => $rows->pluck('id')->all() === [$thread->certification_id]);
        $this->get(route('qa-board.edit', $thread))->assertOk()->assertDontSee('name="certification_id"', false);
        $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertOk();
        $this->get(route('qa-board.replies.edit', [QaThread::factory()->create(), $reply]))->assertNotFound();
        foreach ([User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('qa-board.create'))->assertForbidden();
            $this->get(route('qa-board.edit', $thread))->assertForbidden();
            $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertForbidden();
        }
        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.edit', $thread))->assertForbidden();
        $thread->certification->update(['status' => CertificationStatus::Draft]);
        $this->get(route('qa-board.show', $thread))->assertForbidden();
        foreach (['invited', 'graduated', 'withdrawn'] as $status) {
            foreach (['student', 'coach'] as $role) {
                $this->actingAs(User::factory()->create(compact('status', 'role')))->get(route('qa-board.index'))->assertForbidden();
            }
        }
    }

    public function test_search_includes_title_question_and_reply_without_leaking_hidden_threads(): void
    {
        $title = QaThread::factory()->create(['title' => '固有検索語を含む', 'body' => '本文']);
        $body = QaThread::factory()->create(['body' => '固有検索語を含む']);
        $replyThread = QaThread::factory()->create();
        QaReply::factory()->count(2)->for($replyThread, 'qaThread')->create(['body' => '固有検索語']);
        $hidden = QaThread::factory()->for(Certification::factory()->draft())->create();
        QaReply::factory()->for($hidden, 'qaThread')->create(['body' => '固有検索語']);
        QaThread::factory()->create();
        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.index', ['keyword' => '固有検索語']))->assertOk();
        $this->assertEqualsCanonicalizing([$title->id, $body->id, $replyThread->id], $response->viewData('threads')->pluck('id')->all());
        $this->getJson(route('qa-board.index', ['keyword' => str_repeat('あ', 101), 'status' => 'invalid', 'page' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['keyword', 'status', 'page']);
    }

    public function test_filter_pagination_and_newest_order_preserve_query(): void
    {
        $cert = Certification::factory()->published()->create();
        $threads = collect();
        for ($i = 0; $i < 21; $i++) {
            $threads->push(QaThread::factory()->for($cert)->create(['title' => '検索対象', 'created_at' => now()->subMinutes($i)]));
        }
        QaThread::factory()->resolved()->for($cert)->create(['title' => '検索対象']);
        QaThread::factory()->create(['title' => '検索対象']);
        $filters = ['keyword' => '検索対象', 'certification_id' => $cert->id, 'status' => 'unresolved'];
        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.index', $filters))->assertOk();
        $page = $response->viewData('threads');
        $this->assertSame(20, $page->perPage());
        $this->assertSame(21, $page->total());
        $this->assertSame($threads->take(20)->pluck('id')->all(), $page->pluck('id')->all());
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $query);
        foreach ($filters as $key => $value) {
            $this->assertSame($value, $query[$key]);
        }
        $this->get($page->nextPageUrl())->assertOk()->assertViewHas('threads', fn ($rows) => $rows->pluck('id')->all() === [$threads->last()->id]);
        $this->get(route('qa-board.index', ['status' => 'resolved', 'certification_id' => $cert->id]))->assertOk()
            ->assertViewHas('threads', fn ($rows) => $rows->total() === 1);
    }

    public function test_show_orders_replies_eager_loads_authors_and_preserves_withdrawn_names(): void
    {
        $thread = QaThread::factory()->create();
        $late = QaReply::factory()->for($thread, 'qaThread')->create(['created_at' => now()]);
        $early = QaReply::factory()->for($thread, 'qaThread')->for($thread->user)->create(['created_at' => now()->subDay()]);
        $authorName = $thread->user->name;
        $thread->user->delete();
        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.show', $thread))->assertOk()->assertSee($authorName);
        $loaded = $response->viewData('thread');
        $this->assertTrue($loaded->relationLoaded('user'));
        $this->assertTrue($loaded->relationLoaded('certification'));
        $this->assertSame([$early->id, $late->id], $loaded->replies->pluck('id')->all());
        $this->assertSame(2, $loaded->replies_count);
        foreach ($loaded->replies as $reply) {
            $this->assertTrue($reply->relationLoaded('user'));
            $this->assertSame($loaded, $reply->qaThread);
        }
    }

    public function test_dashboard_activation_does_not_expose_non_public_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $published = QaThread::factory()->create();
        $draft = QaThread::factory()->for(Certification::factory()->draft())->create();
        foreach ([$published, $draft] as $thread) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id]);
        }
        $this->actingAs($coach)->get(route('dashboard.index'))->assertOk()
            ->assertViewHas('viewModel', fn ($model) => $model->unansweredQaCount === 1
                && $model->recentQaThreads->pluck('id')->all() === [$published->id]);
    }

    public function test_coach_reply_controls_do_not_add_assignment_queries_per_reply(): void
    {
        $thread = QaThread::factory()->create();
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id]);
        QaReply::factory()->for($thread, 'qaThread')->for($coach)->create();
        $counts = [];
        $this->actingAs($coach);
        try {
            foreach ([1, 10] as $count) {
                if ($count === 10) {
                    QaReply::factory()->count(9)->for($thread, 'qaThread')->for($coach)->create();
                }
                DB::enableQueryLog();
                DB::flushQueryLog();
                $this->get(route('qa-board.show', $thread))->assertOk();
                $counts[] = count(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'certification_coach_assignments')));
                DB::disableQueryLog();
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($counts[0], $counts[1]);
        // 入口Policyのexistsと表示用coachesの一括取得の2回だけ。
        $this->assertSame([2, 2], $counts);
    }

    public function test_loaded_coaches_are_current_and_assignment_removal_blocks_next_request(): void
    {
        $thread = QaThread::factory()->create();
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $coach->id]);
        CertificationCoachAssignment::factory()->create(['certification_id' => $thread->certification_id, 'user_id' => $other->id, 'unassigned_at' => now()]);
        $reply = QaReply::factory()->for($thread, 'qaThread')->for($coach)->create();
        $loaded = $this->actingAs($coach)->get(route('qa-board.show', $thread))->assertOk()->viewData('thread');
        $this->assertSame([$coach->id], $loaded->certification->coaches->pluck('id')->all());
        $this->assertFalse($other->can('view', $loaded));
        $this->assertTrue($coach->can('update', $loaded->replies->first()));
        $this->assertTrue($coach->can('delete', $loaded->replies->first()));

        // 表示用Relationを次のHTTP操作へ持ち越さず、解除直後の閲覧・本人編集・本人削除を拒否する。
        $assignment->update(['unassigned_at' => now()]);
        $this->get(route('qa-board.show', $thread))->assertForbidden();
        $this->patchJson(route('qa-board.replies.update', [$thread, $reply]), ['body' => '更新'])->assertForbidden();
        $this->deleteJson(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'body' => $reply->body]);
        $reloaded = app(ShowAction::class)($thread, $coach);
        $this->assertFalse($coach->can('view', $reloaded));
    }

    public function test_student_and_admin_details_do_not_load_coach_relation(): void
    {
        $thread = QaThread::factory()->create();
        foreach ([$thread->user, User::factory()->admin()->create()] as $viewer) {
            $loaded = $this->actingAs($viewer)->get(route('qa-board.show', $thread))->assertOk()->viewData('thread');
            $this->assertFalse($loaded->certification->relationLoaded('coaches'));
        }
    }

    public function test_relation_query_count_does_not_grow_with_reply_count(): void
    {
        $thread = QaThread::factory()->create();
        QaReply::factory()->for($thread, 'qaThread')->create();
        $counts = [];
        try {
            foreach ([1, 10] as $count) {
                if ($count === 10) {
                    QaReply::factory()->count(9)->for($thread, 'qaThread')->create();
                }
                DB::enableQueryLog();
                DB::flushQueryLog();
                app(ShowAction::class)($thread->fresh());
                $counts[] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($counts[0], $counts[1]);
    }
}
