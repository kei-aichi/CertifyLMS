<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use RuntimeException;

final class CheckoutFulfillmentException extends RuntimeException
{
    public function __construct(string $message, public readonly string $category, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function invalid(string $message = '決済情報を確認できませんでした。'): self
    {
        return new self($message, 'permanent_mismatch');
    }

    public static function unpaid(string $message = '決済はまだ確定していません。'): self
    {
        return new self($message, 'unpaid');
    }

    public static function retryable(string $message = '決済確認を一時的に完了できませんでした。', ?\Throwable $previous = null): self
    {
        return new self($message, 'retryable', $previous);
    }
}
