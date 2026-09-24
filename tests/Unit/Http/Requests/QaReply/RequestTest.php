<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaReply;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 回答本文の入力境界と、入力された投稿者・回答先がvalidated()に含まれないことを保証する。
 * 認可対象はリクエスト本文ではなく、ルートにバインドされたModelを使用する。
 */
class RequestTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('bodyCases')]
    public function test_body_rules_for_store_and_update(mixed $body, bool $valid): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make(['body' => $body], $request->rules());
            $this->assertSame($valid, $validator->passes());
            if (! $valid) {
                $this->assertArrayHasKey('body', $validator->errors()->toArray());
            }
        }
    }

    public static function bodyCases(): iterable
    {
        // 日本語の上限ちょうどと超過を、投稿・編集の両方で確認する。
        yield 'limit' => [str_repeat('あ', 5000), true];
        yield 'over' => [str_repeat('あ', 5001), false];
        yield 'empty' => ['', false];
        yield 'whitespace' => ['   ', false];
        yield 'null' => [null, false];
        yield 'array' => [['text'], false];
        yield 'number' => [123, false];
    }

    public function test_body_is_required_with_japanese_attribute(): void
    {
        app()->setLocale('ja');
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make([], $request->rules(), $request->messages(), $request->attributes());
            $this->assertSame('本文 を入力してください。', $validator->errors()->first('body'));
        }
    }

    public function test_validated_data_contains_only_body(): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $request->setValidator(Validator::make([
                'body' => '回答本文', 'user_id' => 'forged-user', 'qa_thread_id' => 'forged-thread',
                'status' => 'resolved', 'resolved_at' => '2026-01-01 12:00:00',
            ], $request->rules()));
            $this->assertSame(['body' => '回答本文'], $request->validated());
        }
    }

    public function test_authorization_delegates_using_bound_models(): void
    {
        $reply = QaReply::factory()->create();
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $this->assertFalse($request->authorize(), 'バインドされた対象がない場合は拒否する');
            $store = $request instanceof StoreRequest;
            $route = $this->createMock(Route::class);
            $route->method('parameter')->with($store ? 'thread' : 'reply', null)
                ->willReturn($store ? $reply->qaThread : $reply);
            $request->setRouteResolver(fn () => $route);
            $request->merge(['user_id' => 'forged-user', 'qa_thread_id' => 'forged-thread']);
            foreach ([$reply->user, User::factory()->student()->create(), User::factory()->admin()->create()] as $user) {
                $request->setUserResolver(fn () => $user);
                $expected = $store ? $user->can('create', [QaReply::class, $reply->qaThread]) : $user->can('update', $reply);
                $this->assertSame($expected, $request->authorize());
            }
            $request->setUserResolver(fn () => null);
            $this->assertFalse($request->authorize());
        }
    }
}
