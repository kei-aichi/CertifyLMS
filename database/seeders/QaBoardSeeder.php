<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 固定受講生と公開資格・現役担当コーチを使うQ&Aのデモデータ。
 * 回答の有無と解決状態の組合せで、本人削除・自己解決・回答表示を確認できる。
 * UserSeeder / CertificationSeeder の後に実行し、受講登録は前提にしない。
 */
final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')
            ->where('role', UserRole::Student)->where('status', UserStatus::InProgress)->first();
        $certification = Certification::query()->published()
            ->whereHas('coaches', fn ($query) => $query
                ->where('role', UserRole::Coach)->where('status', UserStatus::InProgress))
            ->orderBy('id')->first();

        if ($student === null || $certification === null) {
            $this->command?->warn('QaBoardSeeder: 固定受講生と現役担当コーチのいる公開資格を先に作成してください。');

            return;
        }

        $coach = $certification->coaches()->where('role', UserRole::Coach)
            ->where('status', UserStatus::InProgress)->orderBy('users.id')->firstOrFail();

        // 固定IDはデモ識別専用。同文の実投稿を重複扱いせず、編集済みデモも再実行で上書きしない。
        $scenarios = [
            ['id' => '01J00000000000000000000001', 'title' => '学習の進め方を相談したいです', 'resolved' => false, 'replies' => []],
            ['id' => '01J00000000000000000000002', 'title' => '用語の意味を自己解決しました', 'resolved' => true, 'replies' => []],
            ['id' => '01J00000000000000000000003', 'title' => '復習方法について教えてください', 'resolved' => false, 'replies' => [
                ['user_id' => $student->id, 'body' => '補足です。苦手な分野を繰り返し復習しています。'],
                ['user_id' => $coach->id, 'body' => '間違えた理由を言葉にしてから、関連する教材を読み直してみましょう。'],
                ['user_id' => $student->id, 'body' => 'ありがとうございます。説明できるか確認してみます。'],
            ]],
            ['id' => '01J00000000000000000000004', 'title' => '学習計画の疑問が解消しました', 'resolved' => true, 'replies' => [
                ['user_id' => $coach->id, 'body' => '学習計画には復習の時間も含めてください。'],
            ]],
        ];

        DB::transaction(function () use ($scenarios, $student, $certification): void {
            foreach ($scenarios as $index => $scenario) {
                $createdAt = now()->subDays(4 - $index);
                $resolvedAt = $scenario['resolved'] ? $createdAt->copy()->addHours(4) : null;
                $thread = QaThread::firstOrCreate(['id' => $scenario['id']], [
                    'certification_id' => $certification->id,
                    'user_id' => $student->id,
                    'title' => $scenario['title'],
                    'body' => $certification->name.'の学習について相談します。'.$scenario['title'],
                    'status' => $scenario['resolved'] ? QaThreadStatus::Resolved : QaThreadStatus::Open,
                    'resolved_at' => $resolvedAt,
                    'created_at' => $createdAt,
                    'updated_at' => $resolvedAt ?? $createdAt,
                ]);

                // 回答は初回だけ作成する。再実行で削除した回答を復活させたり、追加投稿を消したりしない。
                if (! $thread->wasRecentlyCreated) {
                    continue;
                }

                foreach ($scenario['replies'] as $offset => $reply) {
                    $postedAt = $createdAt->copy()->addHours($offset + 1);
                    $thread->replies()->create($reply + ['created_at' => $postedAt, 'updated_at' => $postedAt]);
                }
            }
        });
    }
}
