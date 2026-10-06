<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Plan;

use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 既存画面と確定仕様の入力境界を保証する。未確定項目を保存用データへ混入させない。
 */
class RequestTest extends TestCase
{
    /** @dataProvider inputCases */
    public function test_confirmed_input_boundaries(string $field, mixed $value, bool $valid): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $input = array_replace(['name' => 'プラン', 'duration_days' => 30, 'default_meeting_quota' => 0], [$field => $value]);
            $validator = Validator::make($input, $request->rules());
            $this->assertSame($valid, $validator->passes(), get_class($request).': '.$field);
        }
    }

    public static function inputCases(): array
    {
        return [
            'name required' => ['name', '', false],
            'name string' => ['name', [], false],
            'name 100' => ['name', str_repeat('あ', 100), true],
            'name 101' => ['name', str_repeat('あ', 101), false],
            'description null' => ['description', null, true],
            'description string' => ['description', [], false],
            'description 2000' => ['description', str_repeat('あ', 2000), true],
            'description 2001' => ['description', str_repeat('あ', 2001), false],
            'duration required' => ['duration_days', null, false],
            'duration zero' => ['duration_days', 0, false],
            'duration minimum' => ['duration_days', 1, true],
            'duration maximum' => ['duration_days', 3650, true],
            'duration overflow' => ['duration_days', 3651, false],
            'duration fraction' => ['duration_days', 1.5, false],
            'quota required' => ['default_meeting_quota', null, false],
            'quota negative' => ['default_meeting_quota', -1, false],
            'quota zero' => ['default_meeting_quota', 0, true],
            'quota maximum' => ['default_meeting_quota', 1000, true],
            'quota overflow' => ['default_meeting_quota', 1001, false],
            'quota fraction' => ['default_meeting_quota', 0.5, false],
        ];
    }

    public function test_unconfirmed_and_server_managed_fields_are_excluded(): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $basic = ['name' => 'プラン', 'duration_days' => 30, 'default_meeting_quota' => 0];
            $validator = Validator::make($basic + [
                'sort_order' => 10,
                'status' => 'published',
                'created_by_user_id' => 'forged',
                'updated_by_user_id' => 'forged',
            ], $request->rules());

            // 作成のみ並び順を受け付ける。更新入力は後続Stepまで変更しない。
            $expected = $request instanceof StoreRequest ? $basic + ['sort_order' => 10] : $basic;
            $this->assertSame($expected, $validator->validated());
        }
    }

    public function test_index_accepts_only_known_status_and_screen_keyword_limit(): void
    {
        $rules = (new IndexRequest)->rules();
        foreach ([null, 'draft', 'published', 'archived'] as $status) {
            $this->assertTrue(Validator::make(['status' => $status, 'keyword' => str_repeat('あ', 100)], $rules)->passes());
        }
        $this->assertFalse(Validator::make(['status' => 'unknown'], $rules)->passes());
        $this->assertFalse(Validator::make(['keyword' => str_repeat('あ', 101)], $rules)->passes());
    }
}
