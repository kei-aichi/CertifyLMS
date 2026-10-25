<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない資格マスタを削除しようとした際の例外（HTTP 409）。
 * 下書き・関連データなしの条件による削除拒否に使用する。
 */
final class CertificationNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('関連する受講履歴・質問・教材データが存在するため、削除できません。', $previous);
    }
}
