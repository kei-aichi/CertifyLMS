<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use RuntimeException;

final class GeminiApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $upstreamStatus,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
