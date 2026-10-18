<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AiChat\AiChatPrompt;
use App\DTOs\AiChat\GeminiChatResult;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

final class GeminiChatService
{
    public function generate(AiChatPrompt $prompt): GeminiChatResult
    {
        $apiKey = config('ai-chat.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new GeminiApiKeyMissingException('Gemini API key is not configured.');
        }

        $model = (string) config('ai-chat.gemini.model', 'gemini-2.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $maxRetries = max(0, (int) config('ai-chat.gemini.max_retries', 2));
        $startedAt = microtime(true);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->connectTimeout((int) config('ai-chat.gemini.connect_timeout', 10))
                    ->timeout((int) config('ai-chat.gemini.timeout', 30))
                    ->post($url, $prompt->toGeminiPayload());
            } catch (ConnectionException $exception) {
                throw new GeminiApiException('Gemini connection failed.', 0, false);
            }

            $status = $response->status();
            if ($this->isRetryable($status) && $attempt < $maxRetries) {
                usleep($attempt === 0 ? 250000 : 500000);

                continue;
            }
            if (! $response->successful()) {
                throw new GeminiApiException('Gemini request failed.', $status, $this->isRetryable($status));
            }

            try {
                $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new GeminiApiException('Gemini returned invalid JSON.', $status);
            }

            $finishReason = data_get($payload, 'candidates.0.finishReason');
            if ($finishReason === 'SAFETY' || data_get($payload, 'promptFeedback.blockReason') !== null) {
                throw new GeminiApiException('Gemini response was blocked by safety filters.', $status);
            }

            $parts = data_get($payload, 'candidates.0.content.parts', []);
            $text = collect(is_array($parts) ? $parts : [])
                ->pluck('text')->filter(fn ($value) => is_string($value))->implode('');
            if ($text === '') {
                throw new GeminiApiException('Gemini returned an empty response.', $status);
            }

            return new GeminiChatResult(
                text: $text,
                model: data_get($payload, 'modelVersion') ?? data_get($payload, 'model'),
                inputTokens: data_get($payload, 'usageMetadata.promptTokenCount'),
                outputTokens: data_get($payload, 'usageMetadata.candidatesTokenCount'),
                responseTimeMs: (int) round((microtime(true) - $startedAt) * 1000),
                finishReason: is_string($finishReason) ? $finishReason : null,
                upstreamStatus: $status,
            );
        }

        throw new GeminiApiException('Gemini request failed.', 500, true);
    }

    private function isRetryable(int $status): bool
    {
        return in_array($status, [429, 500, 502, 503, 504], true);
    }
}
