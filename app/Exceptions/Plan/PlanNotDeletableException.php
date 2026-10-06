<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** 削除条件違反は業務競合とし、HTML応答への変換は共通Handlerに委ねる。 */
final class PlanNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('下書き状態で、ユーザー・プラン履歴から参照されていないプランのみ削除できます。', $previous);
    }
}
