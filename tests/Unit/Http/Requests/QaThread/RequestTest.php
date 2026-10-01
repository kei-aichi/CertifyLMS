<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 質問の入力境界と、サーバー管理項目がvalidated()に混入しないことを保証する。
 * HTTPルートは追加せず、既存と同じValidatorと実際のPolicyを利用する。
 */
class RequestTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('textCases')]
    public function test_text_rules_for_store_and_update(string $field, mixed $value, bool $valid): void
    {
        $certification = Certification::factory()->published()->create();
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $payload = ['certification_id' => $certification->id, 'title' => '質問', 'body' => '本文'];
            $payload[$field] = $value;
            $validator = Validator::make($payload, $request->rules());
            $this->assertSame($valid, $validator->passes());
            if (! $valid) {
                $this->assertArrayHasKey($field, $validator->errors()->toArray());
            }
        }
    }

    public static function textCases(): iterable
    {
        foreach (['title' => 200, 'body' => 5000] as $field => $max) {
            // 日本語でもバイト数ではなく文字数の上限を保証する。
            yield $field.' limit' => [$field, str_repeat('あ', $max), true];
            yield $field.' over' => [$field, str_repeat('あ', $max + 1), false];
            yield $field.' empty' => [$field, '', false];
            yield $field.' whitespace' => [$field, '   ', false];
            yield $field.' null' => [$field, null, false];
            yield $field.' array' => [$field, ['text'], false];
            yield $field.' number' => [$field, 123, false];
        }
    }

    public function test_required_fields_and_japanese_messages(): void
    {
        app()->setLocale('ja');
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make([], $request->rules(), $request->messages(), $request->attributes());
            $this->assertSame('タイトル を入力してください。', $validator->errors()->first('title'));
            $this->assertSame('本文 を入力してください。', $validator->errors()->first('body'));
            if ($request instanceof StoreRequest) {
                $this->assertArrayHasKey('certification_id', $validator->errors()->toArray());
            }
        }
    }

    public function test_only_existing_published_certification_is_valid_without_enrollment(): void
    {
        foreach (CertificationStatus::cases() as $status) {
            $certification = Certification::factory()->create(['status' => $status]);
            $validator = Validator::make([
                'certification_id' => $certification->id, 'title' => '質問', 'body' => '本文',
            ], (new StoreRequest)->rules());
            $this->assertSame($status === CertificationStatus::Published, $validator->passes());
            if ($status !== CertificationStatus::Published) {
                $this->assertArrayHasKey('certification_id', $validator->errors()->toArray());
            }
        }
        $this->assertSame(0, Enrollment::count());
    }

    public function test_invalid_or_nonexistent_certification_ids_are_input_errors(): void
    {
        foreach (['', null, 'invalid-ulid', (string) Str::ulid(), ['invalid'], 123] as $id) {
            $validator = Validator::make([
                'certification_id' => $id, 'title' => '質問', 'body' => '本文',
            ], (new StoreRequest)->rules());
            $this->assertArrayHasKey('certification_id', $validator->errors()->toArray());
        }
    }

    public function test_validated_data_excludes_identity_state_and_update_certification(): void
    {
        $otherCertification = Certification::factory()->published()->create();
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $input = [
                'certification_id' => $otherCertification->id,
                'title' => '編集タイトル', 'body' => '編集本文',
                'user_id' => User::factory()->create()->id,
                'status' => 'resolved', 'resolved_at' => '2026-01-01 12:00:00',
                'id' => (string) Str::ulid(),
            ];
            $request->setValidator(Validator::make($input, $request->rules()));
            $expected = ['title' => $input['title'], 'body' => $input['body']];
            if ($request instanceof StoreRequest) {
                $expected = ['certification_id' => $otherCertification->id] + $expected;
            }
            $this->assertSame($expected, $request->validated());
        }
    }

    public function test_store_authorization_delegates_to_policy_before_input_validation(): void
    {
        $request = new StoreRequest;
        $this->assertFalse($request->authorize());
        foreach ([User::factory()->student()->create(), User::factory()->coach()->create(), User::factory()->admin()->create()] as $user) {
            $request->setUserResolver(fn () => $user);
            $this->assertSame($user->can('create', QaThread::class), $request->authorize());
        }
        // 対象資格の入力不備で403にせず、利用可能な受講生はrules()まで進める。
        $student = User::factory()->student()->create();
        $request->setUserResolver(fn () => $student);
        $request->merge(['certification_id' => ['invalid']]);
        $this->assertTrue($request->authorize());
    }

    public function test_update_authorization_uses_bound_thread_not_input(): void
    {
        $thread = QaThread::factory()->create();
        $other = User::factory()->student()->create();
        $request = new UpdateRequest;
        $route = $this->createMock(Route::class);
        $route->method('parameter')->with('thread', null)->willReturn($thread);
        $request->setRouteResolver(fn () => $route);
        $request->merge(['user_id' => $other->id]);
        foreach ([$thread->user, $other, User::factory()->admin()->create()] as $user) {
            $request->setUserResolver(fn () => $user);
            $this->assertSame($user->can('update', $thread), $request->authorize());
        }
        $request->setUserResolver(fn () => null);
        $this->assertFalse($request->authorize());
        $this->assertFalse((new UpdateRequest)->authorize());
    }
}
