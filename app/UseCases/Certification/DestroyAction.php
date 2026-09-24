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
                    || $certification->enrollments()->withTrashed()->exists()) {
                    throw new CertificationNotDeletableException;
                }

                // Q&Aによる削除禁止はDBのrestrict制約に任せ、投稿を連鎖削除しない。
                $certification->delete();
            });
        } catch (QueryException $exception) {
            // MySQLのQ&A参照制約違反だけを業務上の削除拒否へ変換する。他のSQL障害は隠さない。
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451
                && str_contains($exception->getMessage(), 'qa_threads_certification_id_foreign')) {
                throw new CertificationNotDeletableException($exception);
            }

            throw $exception;
        }
    }
}
