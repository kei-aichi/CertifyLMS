<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Database\Seeders\CertificationCategorySeeder;
use Database\Seeders\CertificationSeeder;
use Database\Seeders\QaBoardSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 実際の固定デモアカウント・資格Seederとの接続と、Q&A単独再実行時の非破壊性を保証する。
 * Enrollmentなしで、本人削除・自己解決・担当コーチ回答を試せるデータを検証する。
 */
class QaBoardSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_scenarios_using_existing_users_and_assignments(): void
    {
        $this->seed([UserSeeder::class, CertificationCategorySeeder::class, CertificationSeeder::class]);
        $users = User::withTrashed()->count();
        $certifications = Certification::count();
        $this->seed(QaBoardSeeder::class);
        $threads = QaThread::with(['certification.coaches', 'user', 'replies.user'])->orderBy('created_at')->get();

        $this->assertCount(4, $threads);
        $this->assertSame([0, 0, 3, 1], $threads->map(fn ($thread) => $thread->replies->count())->all());
        $this->assertSame([QaThreadStatus::Open, QaThreadStatus::Resolved, QaThreadStatus::Open, QaThreadStatus::Resolved], $threads->pluck('status')->all());
        $this->assertSame(4, $threads->pluck('created_at')->unique()->count());

        foreach ($threads as $thread) {
            $this->assertTrue(Str::isUlid($thread->id));
            $this->assertSame('student@certify-lms.test', $thread->user->email);
            $this->assertSame(CertificationStatus::Published, $thread->certification->status);
            if ($thread->status === QaThreadStatus::Open) {
                $this->assertNull($thread->resolved_at);
                $this->assertTrue($thread->updated_at->equalTo($thread->created_at));
            } else {
                $this->assertTrue($thread->resolved_at->greaterThan($thread->created_at));
                $this->assertTrue($thread->updated_at->equalTo($thread->resolved_at));
            }

            foreach ($thread->replies as $reply) {
                // コーチというロールだけでなく、質問対象資格の現役担当者であることを確認する。
                if ($reply->user->role === UserRole::Coach) {
                    $this->assertTrue($thread->certification->coaches->contains('id', $reply->user_id));
                }
                $this->assertTrue($reply->created_at->greaterThan($thread->created_at));
                if ($thread->resolved_at !== null) {
                    $this->assertTrue($reply->created_at->lessThan($thread->resolved_at));
                }
            }
        }

        $this->assertSame(2, QaReply::whereHas('user', fn ($query) => $query->where('role', UserRole::Student))->count());
        $this->assertSame(2, QaReply::whereHas('user', fn ($query) => $query->where('role', UserRole::Coach))->count());
        $this->assertSame($users, User::withTrashed()->count());
        $this->assertSame($certifications, Certification::count());
        $this->assertSame(0, Enrollment::count());

        // 単独再実行では件数だけでなく、時刻・本文も変わらない。
        $beforeThreads = QaThread::orderBy('id')->get()->toArray();
        $beforeReplies = QaReply::orderBy('id')->get()->toArray();
        $this->travel(1)->day();
        $this->seed(QaBoardSeeder::class);
        $this->assertSame($beforeThreads, QaThread::orderBy('id')->get()->toArray());
        $this->assertSame($beforeReplies, QaReply::orderBy('id')->get()->toArray());

        // 手動検証でタイトルや回答を変更した場合も、デモを重複生成・復元しない。
        $threads->first()->update(['title' => '手動で変更したタイトル']);
        $threads[2]->replies->first()->delete();
        $this->seed(QaBoardSeeder::class);
        $this->assertSame(4, QaThread::count());
        $this->assertSame(3, QaReply::count());
        $this->assertSame('手動で変更したタイトル', $threads->first()->fresh()->title);
    }

    public function test_missing_prerequisites_do_not_create_replacement_users_or_certifications(): void
    {
        $this->seed(QaBoardSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Certification::count());
        $this->assertSame(0, QaThread::count());
        $this->assertSame(0, QaReply::count());
    }

    public function test_non_public_certifications_and_unassigned_coaches_are_not_used(): void
    {
        User::factory()->student()->inProgress()->create(['email' => 'student@certify-lms.test']);
        $draft = Certification::factory()->draft()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $draft->id]);
        $published = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->unassigned()->create(['certification_id' => $published->id]);

        // 資格が公開済みでも過去の担当履歴だけでは回答者を選べず、下書き資格もデモ対象外。
        $this->seed(QaBoardSeeder::class);

        $this->assertSame(0, QaThread::count());
        $this->assertSame(0, QaReply::count());
        $this->assertSame(0, Enrollment::count());
    }
}
