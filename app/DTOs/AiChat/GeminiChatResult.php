<?php

declare(strict_types=1);

namespace App\DTOs\AiChat;

final readonly class GeminiChatResult
{
    public function __construct(
        public string $text,
        public ?string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $responseTimeMs,
        public ?string $finishReason,
        public int $upstreamStatus,
    ) {}
}
