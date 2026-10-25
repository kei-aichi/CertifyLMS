<?php

declare(strict_types=1);

namespace App\UseCases\Certification;

use App\Enums\CertificationStatus;
use App\Exceptions\Certification\CertificationNotDeletableException;
use App\Models\Certification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** 資格を物理削除する。論理削除済みも含む受講登録がなく、下書きの場合のみ許可する。 */
final class DestroyAction
{
    public function __invoke(Certification $certification): void
    {
        try {
            DB::transaction(function () use ($certification) {
                $certification = Certification::query()->lockForUpdate()->findOrFail($certification->id);
                // SoftDelete済みでもFKの参照は残るため、通常Relationの件数だけで判定しない。
                if ($certification->status !== CertificationStatus::Draft
                    || $certification->enrollments()->withTrashed()->exists()
                    || $certification->parts()->exists()
                    || $certification->certificates()->exists()
                    || $certification->mockExams()->exists()
                    || $certification->questionCategories()->exists()
                    || DB::table('qa_threads')->where('certification_id', $certification->id)->exists()
                    || DB::table('announcements')->where('target_certification_id', $certification->id)->exists()) {
                    throw new CertificationNotDeletableException;
                }

                $certification->delete();
            });
        } catch (QueryException $exception) {
            $restrictedForeignKeys = [
                'parts_certification_id_foreign',
                'enrollments_certification_id_foreign',
                'certificates_certification_id_foreign',
                'mock_exams_certification_id_foreign',
                'qa_threads_certification_id_foreign',
                'question_categories_certification_id_foreign',
                'announcements_target_certification_id_foreign',
            ];

            if ((int) ($exception->errorInfo[1] ?? 0) === 1451
                && collect($restrictedForeignKeys)
                    ->contains(fn (string $constraint) => str_contains($exception->getMessage(), $constraint))) {
                throw new CertificationNotDeletableException($exception);
            }

            throw $exception;
        }
    }
}
