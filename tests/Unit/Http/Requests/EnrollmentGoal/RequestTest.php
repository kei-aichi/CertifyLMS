<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\EnrollmentGoal;

use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RequestTest extends TestCase
{
    /** @dataProvider inputCases */
    public function test_goal_input_rules(string $field, mixed $value, bool $valid): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $input = ['title' => '目標', 'description' => null, 'target_date' => null];
            $input[$field] = $value;
            $this->assertSame($valid, Validator::make($input, $request->rules())->passes(), $field);
        }
    }

    public static function inputCases(): array
    {
        return [
            'title missing' => ['title', null, false],
            'title 100' => ['title', str_repeat('あ', 100), true],
            'title 101' => ['title', str_repeat('あ', 101), false],
            'description null' => ['description', null, true],
            'description 1000' => ['description', str_repeat('あ', 1000), true],
            'description 1001' => ['description', str_repeat('あ', 1001), false],
            'target date null' => ['target_date', null, true],
            'target date today' => ['target_date', now()->toDateString(), true],
            'target date valid' => ['target_date', '2020-01-01', true],
            'target date invalid' => ['target_date', 'not-a-date', false],
        ];
    }

    public function test_management_fields_are_not_validated(): void
    {
        $input = [
            'title' => '目標',
            'description' => null,
            'target_date' => null,
            'achieved_at' => now()->toDateTimeString(),
            'enrollment_id' => 'forged',
            'user_id' => 'forged',
        ];

        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $this->assertSame(
                ['title' => '目標', 'description' => null, 'target_date' => null],
                Validator::make($input, $request->rules())->validated(),
            );
        }
    }
}
