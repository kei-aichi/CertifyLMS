<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない資格マスタを削除しようとした際の例外（HTTP 409）。
 * 下書き・受講登録なしの条件と、Q&Aの参照制約による削除拒否に使用する。
 */
final class CertificationNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('受講登録（削除済みを含む）と質問がない、下書き状態の資格のみ削除できます。', $previous);
    }
}
