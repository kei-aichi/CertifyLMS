<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingPack;

use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 基本情報の入力境界と、状態・管理者情報を validated() に含めない契約を保証する。
 */
class RequestTest extends TestCase
{
    #[DataProvider('fieldCases')]
    public function test_store_and_update_boundaries(string $field, mixed $value, bool $valid): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $input = array_replace(['name' => '面談パック', 'meeting_count' => 1, 'price' => 0], [$field => $value]);
            $validator = Validator::make($input, $request->rules());
            $this->assertSame($valid, $validator->passes());
            if (! $valid) {
                $this->assertArrayHasKey($field, $validator->errors()->toArray());
            }
        }
    }

    public static function fieldCases(): iterable
    {
        foreach (['name' => 100, 'description' => 2000, 'stripe_price_id' => 255] as $field => $max) {
            yield $field.' limit' => [$field, str_repeat('あ', $max), true];
            yield $field.' over' => [$field, str_repeat('あ', $max + 1), false];
            yield $field.' array' => [$field, [], false];
        }
        yield 'empty name' => ['name', '', false];
        yield 'null name' => ['name', null, false];
        yield 'optional description' => ['description', null, true];
        yield 'optional price id' => ['stripe_price_id', null, true];
        yield 'optional sort' => ['sort_order', null, true];
        foreach (['meeting_count' => [1, 100], 'price' => [0, 1000000], 'sort_order' => [0, 4294967295]] as $field => [$min, $max]) {
            yield $field.' min' => [$field, $min, true];
            yield $field.' max' => [$field, $max, true];
            yield $field.' below' => [$field, $min - 1, false];
            yield $field.' above' => [$field, $max + 1, false];
            yield $field.' fraction' => [$field, 1.5, false];
        }
    }

    public function test_required_fields_and_japanese_attributes(): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make([], $request->rules());
            foreach (['name', 'meeting_count', 'price'] as $field) {
                $this->assertArrayHasKey($field, $validator->errors()->toArray());
                $this->assertNotSame($field, $request->attributes()[$field]);
            }
        }
    }

    public function test_only_basic_information_is_validated(): void
    {
        $basic = ['name' => '面談パック', 'meeting_count' => 5, 'price' => 3000];
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $request->setValidator(Validator::make($basic + [
                'status' => 'published',
                'created_by_user_id' => 'forged',
                'updated_by_user_id' => 'forged',
            ], $request->rules()));
            $this->assertSame($basic, $request->validated());
        }
    }

    public function test_index_filters_accept_only_defined_values(): void
    {
        $rules = (new IndexRequest)->rules();
        foreach (['draft', 'published', 'archived', null] as $status) {
            $this->assertTrue(Validator::make(['keyword' => str_repeat('あ', 100), 'status' => $status, 'page' => 1], $rules)->passes());
        }
        $this->assertTrue(Validator::make([], $rules)->passes());
        foreach ([
            ['keyword' => str_repeat('あ', 101)], ['keyword' => []],
            ['status' => 'unknown'], ['page' => 0], ['page' => 1.5], ['page' => null],
        ] as $input) {
            $this->assertTrue(Validator::make($input, $rules)->fails());
        }
    }
}
