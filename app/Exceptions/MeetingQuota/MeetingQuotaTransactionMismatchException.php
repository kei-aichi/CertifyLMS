<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 面談キャンセル対象の消費履歴が欠損または不整合の場合に throw する(HTTP 409)。
 */
final class MeetingQuotaTransactionMismatchException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            '面談回数の消費履歴を確認できないため、面談をキャンセルできません。',
            $previous,
        );
    }
}
