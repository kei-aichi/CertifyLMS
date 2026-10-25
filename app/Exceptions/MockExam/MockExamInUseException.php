<?php

declare(strict_types=1);

namespace App\Exceptions\MockExam;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 模試マスタの削除条件を満たさない場合に発火する例外(HTTP 409)。
 *
 * - 公開中(`is_published = true`)の模試は削除不可
 * - 進行中(NotStarted / InProgress / Submitted)のセッションが残っている模試は削除不可
 */
final class MockExamInUseException extends ConflictHttpException
{
    public static function forPublished(): self
    {
        return new self('公開中の模試は削除できません。先に公開を停止してください。');
    }

    public static function forActiveSessions(): self
    {
        return new self('受験中または採点待ちのセッションが残っているため、模試を削除できません。');
    }

    public static function forHistoricalData(?\Throwable $previous = null): self
    {
        return new self(
            'この模試には受験履歴または解答履歴が存在するため、削除できません。',
            $previous,
        );
    }

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
