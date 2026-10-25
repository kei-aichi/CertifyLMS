<?php

declare(strict_types=1);

namespace App\UseCases\Part;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Part;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Part の SoftDelete ユースケース。Draft 状態のみ削除可、Published 状態は削除拒否(先に下書きへ戻す必要がある)。
 */
final class DestroyAction
{
    /**
     * @throws ContentNotDeletableException
     */
    public function __invoke(Part $part): void
    {
        if ($part->status !== ContentStatus::Draft) {
            throw ContentNotDeletableException::forPart();
        }

        if ($part->chapters()->exists()) {
            throw ContentNotDeletableException::forPartChildren();
        }

        try {
            DB::transaction(fn () => $part->delete());
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451
                && str_contains($exception->getMessage(), 'chapters_part_id_foreign')) {
                throw ContentNotDeletableException::forPartChildren();
            }

            throw $exception;
        }
    }
}
