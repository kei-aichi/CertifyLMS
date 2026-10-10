<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 開発用の受講生メモシーダー。
 *
 * 既存の有効なEnrollment、担当coach、adminだけを利用し、メモ以外の業務データは作成しない。
 */
final class EnrollmentNoteSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->orderBy('created_at')->first();
        if ($admin === null) {
            return;
        }

        $enrollments = Enrollment::query()
            ->with(['user', 'certification.coaches'])
            ->whereNull('deleted_at')
            ->whereHas('user', fn ($query) => $query->whereNull('deleted_at'))
            ->whereHas('certification.coaches')
            ->orderBy('created_at')
            ->get();

        $multi = $enrollments->first(
            fn (Enrollment $enrollment): bool => $enrollment->certification->coaches->count() >= 2,
        );
        if ($multi === null) {
            return;
        }

        $coaches = $multi->certification->coaches->take(2);
        $this->createNote($multi, $coaches[0], '同一受講登録の担当コーチメモ（1）', 1);
        $this->createNote($multi, $coaches[1], '同一受講登録の担当コーチメモ（2）', 2);
        $this->createNote($multi, $admin, '管理者作成メモ（受講状況確認）', 3);

        $studentIds = [$multi->user_id];
        $certificationIds = [$multi->certification_id];
        $offset = 4;

        foreach ($enrollments as $enrollment) {
            if ($enrollment->is($multi)) {
                continue;
            }

            $newStudent = ! in_array($enrollment->user_id, $studentIds, true);
            $newCertification = ! in_array($enrollment->certification_id, $certificationIds, true);
            if (! $newStudent && ! $newCertification) {
                continue;
            }

            $author = $enrollment->certification->coaches->first();
            if ($author === null) {
                continue;
            }

            $this->createNote($enrollment, $author, '担当コーチ作成メモ（受講状況確認）', $offset++);
            $studentIds[] = $enrollment->user_id;
            $certificationIds[] = $enrollment->certification_id;
            if (count($studentIds) >= 3 && count($certificationIds) >= 2) {
                break;
            }
        }
    }

    private function createNote(Enrollment $enrollment, User $author, string $label, int $dayOffset): void
    {
        $body = '[S-B-07 seed] '.$label;
        $createdAt = Carbon::now()->subDays($dayOffset);

        EnrollmentNote::query()->firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'body' => $body],
            ['author_user_id' => $author->id, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        );
    }
}
