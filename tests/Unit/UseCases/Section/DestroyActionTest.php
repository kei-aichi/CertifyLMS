<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Section;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Section;
use App\UseCases\Section\DestroyAction;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @dataProvider targetForeignKeyConstraints
     */
    public function test_converts_target_foreign_key_violation_to_business_exception(string $constraint): void
    {
        $queryException = $this->queryException($constraint, 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('ContentNotDeletableExceptionが発生するはずです。');
        } catch (ContentNotDeletableException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Sectionに子データまたは関連データが存在するため削除できません。', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetForeignKeyConstraints(): array
    {
        return [
            'section images' => ['section_images_section_id_foreign'],
            'learning sessions' => ['learning_sessions_section_id_foreign'],
            'section progresses' => ['section_progresses_section_id_foreign'],
            'question answers' => ['section_question_answers_section_question_id_foreign'],
            'question attempts' => ['section_question_attempts_section_question_id_foreign'],
        ];
    }

    public function test_rethrows_unrelated_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('sections_chapter_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    public function test_rethrows_non_1451_target_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('section_images_section_id_foreign', 1452);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    private function invokeActionWith(QueryException $queryException): void
    {
        $section = Mockery::mock(Section::class)->makePartial();
        $section->shouldReceive('getAttribute')
            ->with('status')
            ->andReturn(ContentStatus::Draft);

        foreach (['images', 'questions', 'learningSessions', 'progresses'] as $relation) {
            $mock = Mockery::mock(HasMany::class);
            $mock->shouldReceive('exists')->once()->andReturn(false);
            $section->shouldReceive($relation)->once()->andReturn($mock);
        }

        $questions = Mockery::mock(HasMany::class);
        $questions->shouldReceive('whereHas')->once()->with('sectionQuestionAnswers')->andReturnSelf();
        $questions->shouldReceive('exists')->once()->andReturn(false);
        $section->shouldReceive('questions')->once()->andReturn($questions);

        $attempts = Mockery::mock(HasMany::class);
        $attempts->shouldReceive('whereHas')->once()->with('sectionQuestionAttempts')->andReturnSelf();
        $attempts->shouldReceive('exists')->once()->andReturn(false);
        $section->shouldReceive('questions')->once()->andReturn($attempts);

        DB::shouldReceive('transaction')->once()->andThrow($queryException);

        (new DestroyAction)($section);
    }

    private function queryException(string $constraint, int $driverCode): QueryException
    {
        $message = 'Cannot delete or update a parent row: a foreign key constraint fails ('.$constraint.')';
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'delete from sections', [], $previous);
    }
}
