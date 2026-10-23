<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\AiChat\AiChatPrompt;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use App\Services\GeminiChatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GeminiChatServiceTest extends TestCase
{
    public function test_success_extracts_text_and_metadata(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'modelVersion' => 'gemini-2.5-flash',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'A'], ['text' => 'B']]]]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 5],
        ])]);
        $result = app(GeminiChatService::class)->generate(new AiChatPrompt('system', [['role' => 'user', 'parts' => [['text' => 'Q']]]]));
        $this->assertSame('AB', $result->text);
        $this->assertSame(3, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key') && isset($request->data()['systemInstruction']));
    }

    public function test_missing_key_is_rejected_without_http(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        Http::fake();
        $this->expectException(GeminiApiKeyMissingException::class);
        app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        Http::assertNothingSent();
    }

    public function test_retryable_status_retries_then_succeeds(): void
    {
        config(['ai-chat.gemini.api_key' => 'test-key', 'ai-chat.gemini.max_retries' => 2]);
        Http::fakeSequence()->pushStatus(503)->pushStatus(429)->push(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);
        $result = app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        $this->assertSame('ok', $result->text);
        Http::assertSentCount(3);
    }

    public function test_empty_response_is_classified_without_exposing_payload(): void
    {
        config(['ai-chat.gemini.api_key' => 'secret-key']);
        Http::fake(['*' => Http::response(['candidates' => []])]);
        try {
            app(GeminiChatService::class)->generate(new AiChatPrompt('private prompt', []));
            $this->fail('Expected exception.');
        } catch (GeminiApiException $exception) {
            $this->assertStringNotContainsString('secret-key', $exception->getMessage());
            $this->assertStringNotContainsString('private prompt', $exception->getMessage());
        }
    }

    public function test_bad_request_is_not_retried(): void
    {
        config(['ai-chat.gemini.api_key' => 'key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad']], 400)]);
        $this->expectException(GeminiApiException::class);
        try {
            app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_continuous_retryable_failures_stop_after_three_requests(): void
    {
        config(['ai-chat.gemini.api_key' => 'key', 'ai-chat.gemini.max_retries' => 2]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(GeminiApiException::class);
        try {
            app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_invalid_json_empty_parts_and_empty_text_are_rejected(): void
    {
        config(['ai-chat.gemini.api_key' => 'key']);
        foreach ([Http::response('{', 200), Http::response(['candidates' => [['content' => ['parts' => []]]]]), Http::response(['candidates' => [['content' => ['parts' => [['text' => '']]]]]])] as $response) {
            Http::fake(['*' => $response]);
            try {
                app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
                $this->fail('Expected GeminiApiException.');
            } catch (GeminiApiException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_safety_block_and_missing_usage_are_handled(): void
    {
        config(['ai-chat.gemini.api_key' => 'key']);
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
        $this->expectException(GeminiApiException::class);
        try {
            app(GeminiChatService::class)->generate(new AiChatPrompt('secret prompt', []));
        } catch (GeminiApiException $e) {
            $this->assertStringNotContainsString('secret prompt', $e->getMessage());
        }
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);
        $result = app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        $this->assertNull($result->inputTokens);
        $this->assertNull($result->outputTokens);
    }

    public function test_request_url_model_and_api_header_are_correct(): void
    {
        config(['ai-chat.gemini.api_key' => 'key', 'ai-chat.gemini.model' => 'gemini-test']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);
        app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
            && $request->hasHeader('x-goog-api-key', 'key'));
    }

    public function test_connection_exception_is_not_retried(): void
    {
        config(['ai-chat.gemini.api_key' => 'key', 'ai-chat.gemini.max_retries' => 2]);
        $requests = 0;
        Http::fake(static function () use (&$requests): never {
            $requests++;
            throw new ConnectionException('connection failed');
        });

        $this->expectException(GeminiApiException::class);
        $this->expectExceptionMessage('Gemini connection failed.');
        try {
            app(GeminiChatService::class)->generate(new AiChatPrompt('system', []));
        } finally {
            $this->assertSame(1, $requests);
        }
    }

    public function test_request_contains_complete_prompt_payload(): void
    {
        config(['ai-chat.gemini.api_key' => 'key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);
        $prompt = new AiChatPrompt('system instruction', [
            ['role' => 'model', 'parts' => [['text' => 'previous answer']]],
            ['role' => 'user', 'parts' => [['text' => 'current question']]],
        ]);

        app(GeminiChatService::class)->generate($prompt);

        Http::assertSent(function ($request) use ($prompt): bool {
            $payload = $request->data();

            return data_get($payload, 'systemInstruction.parts.0.text') === 'system instruction'
                && data_get($payload, 'contents.0.role') === 'model'
                && data_get($payload, 'contents.0.parts.0.text') === 'previous answer'
                && data_get($payload, 'contents.1.role') === 'user'
                && data_get($payload, 'contents.1.parts.0.text') === 'current question'
                && $payload === $prompt->toGeminiPayload();
        });
    }
}
