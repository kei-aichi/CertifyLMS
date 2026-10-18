<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AiChat\AiChatPrompt;

final class AiChatTitleGenerator
{
    public function __construct(private readonly GeminiChatService $gemini) {}

    public function generate(string $question, string $answer): ?string
    {
        $prompt = new AiChatPrompt(
            '資格学習の会話タイトルを日本語で1つだけ生成してください。100文字以内で、タイトル本文のみを返してください。前置き、説明、Markdown見出し、引用符は付けないでください。',
            [[
                'role' => 'user',
                'parts' => [['text' => "質問:\n{$question}\n\n回答:\n{$answer}"]],
            ]],
        );

        $title = trim($this->gemini->generate($prompt, true)->text);
        $title = trim($title, " \t\n\r\0\x0B\"'「」『』#*");

        return $title === '' ? null : mb_substr($title, 0, 100, 'UTF-8');
    }
}
