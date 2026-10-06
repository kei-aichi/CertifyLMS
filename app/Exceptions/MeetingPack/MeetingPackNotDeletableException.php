<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingPack;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** 公開中の削除要求は業務競合とし、HTML応答への変換は共通Handlerに委ねる。 */
final class MeetingPackNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('公開中の面談パックは削除できません。', $previous);
    }
}
