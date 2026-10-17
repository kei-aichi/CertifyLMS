<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MockExam;

use App\Http\Requests\MockExam\UpdateRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 模試マスタ更新 UpdateRequest の rules() バリデーション検証。
 * title / description / order / passing_score の数値レンジ・文字数を Validator::make で網羅する。
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_valid_payload(): void
    {
        // Arrange
        $payload = ['title' => '基本情報模試 第2回', 'description' => '解説付き', 'order' => 1, 'passing_score' => 60];

        // Act
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        // Assert
        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    #[DataProvider('validPassingScores')]
    public function test_passes_for_boundary_passing_score(int $passingScore): void
    {
        $payload = ['title' => 'Sample', 'order' => 0, 'passing_score' => $passingScore];
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    #[DataProvider('invalidCases')]
    public function test_fails_for_invalid_field(string $field, mixed $value): void
    {
        // Arrange
        $payload = array_merge(['title' => 'Sample', 'order' => 0, 'passing_score' => 60], [$field => $value]);

        // Act
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        // Assert
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }

    public function test_fails_when_passing_score_is_missing(): void
    {
        $payload = ['title' => 'Sample', 'order' => 0];
        $validator = Validator::make($payload, (new UpdateRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('passing_score', $validator->errors()->toArray());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidCases(): array
    {
        return [
            'title 未指定で エラー' => ['title', ''],
            'title 101 文字で エラー' => ['title', str_repeat('a', 101)],
            'description 2001 文字で エラー' => ['description', str_repeat('b', 2001)],
            'order 負数で エラー' => ['order', -1],
            'order 65536 で エラー' => ['order', 65536],
            'passing_score 負数で エラー' => ['passing_score', -1],
            'passing_score 101 で エラー' => ['passing_score', 101],
            'passing_score 150 で エラー' => ['passing_score', 150],
            'passing_score 小数で エラー' => ['passing_score', 60.5],
            'passing_score 空欄で エラー' => ['passing_score', ''],
            'passing_score 文字列で エラー' => ['passing_score', 'abc'],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function validPassingScores(): array
    {
        return [
            'passing_score 0' => [0],
            'passing_score 1' => [1],
            'passing_score 100' => [100],
        ];
    }
}
