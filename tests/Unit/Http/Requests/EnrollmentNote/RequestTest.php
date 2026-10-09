<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\EnrollmentNote;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RequestTest extends TestCase
{
    /** @dataProvider invalidBodies */
    public function test_store_request_rejects_invalid_body(mixed $body): void
    {
        $request = new StoreRequest;
        $validator = Validator::make(['body' => $body], $request->rules(), [], $request->attributes());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('メモ本文', $validator->errors()->first('body'));
    }

    /** @dataProvider invalidBodies */
    public function test_update_request_rejects_invalid_body(mixed $body): void
    {
        $request = new UpdateRequest;
        $validator = Validator::make(['body' => $body], $request->rules(), [], $request->attributes());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('メモ本文', $validator->errors()->first('body'));
    }

    public function test_two_thousand_character_body_is_valid_for_both_requests(): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make(
                ['body' => str_repeat('あ', 2000)],
                $request->rules(),
                [],
                $request->attributes(),
            );

            $this->assertFalse($validator->fails());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidBodies(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'ascii whitespace only' => [" \t\n\r"],
            'newline and tab only' => ["\n\t\n"],
            'full width whitespace only' => ['　　'],
            'mixed whitespace only' => [" \t　\n"],
            'over limit' => [str_repeat('あ', 2001)],
            'array' => [['invalid']],
        ];
    }

    public function test_normal_body_and_surrounding_whitespace_are_valid_for_both_requests(): void
    {
        foreach ([new StoreRequest, new UpdateRequest] as $request) {
            $validator = Validator::make(
                ['body' => "　 通常の本文 \t"],
                $request->rules(),
                [],
                $request->attributes(),
            );

            $this->assertFalse($validator->fails());
        }
    }
}
