<?php

declare(strict_types=1);

namespace App\DTOs\AiChat;

final readonly class AiChatPrompt
{
    public function __construct(
        public string $systemInstruction,
        public array $contents,
    ) {}

    public function toGeminiPayload(): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $this->systemInstruction]]],
            'contents' => $this->contents,
        ];
    }
}
