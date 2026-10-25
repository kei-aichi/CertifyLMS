<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\MockExam;

use App\Exceptions\MockExam\MockExamInUseException;
use App\Models\MockExam;
use App\UseCases\MockExam\DestroyAction;
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

    public function test_converts_session_foreign_key_violation_to_historical_data_exception(): void
    {
        $queryException = $this->queryException('mock_exam_sessions_mock_exam_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('MockExamInUseExceptionが発生するはずです。');
        } catch (MockExamInUseException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'この模試には受験履歴または解答履歴が存在するため、削除できません。',
                $exception->getMessage(),
            );
            $this->assertSame($queryException, $exception->getPrevious());
        }
    }

    public function test_converts_answer_foreign_key_violation_to_historical_data_exception(): void
    {
        $queryException = $this->queryException('mock_exam_answers_mock_exam_question_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('MockExamInUseExceptionが発生するはずです。');
        } catch (MockExamInUseException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'この模試には受験履歴または解答履歴が存在するため、削除できません。',
                $exception->getMessage(),
            );
            $this->assertSame($queryException, $exception->getPrevious());
        }
    }

    public function test_rethrows_unrelated_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('mock_exam_questions_category_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    public function test_rethrows_non_1451_target_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('mock_exam_sessions_mock_exam_id_foreign', 1452);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    private function invokeActionWith(QueryException $queryException): void
    {
        $mockExam = Mockery::mock(MockExam::class)->makePartial();
        $mockExam->shouldReceive('getAttribute')
            ->with('is_published')
            ->andReturn(false);

        $sessions = Mockery::mock(HasMany::class);
        $sessions->shouldReceive('whereIn')->once()->andReturnSelf();
        $sessions->shouldReceive('exists')->once()->andReturn(false);
        $mockExam->shouldReceive('sessions')->once()->andReturn($sessions);

        DB::shouldReceive('transaction')->once()->andThrow($queryException);

        (new DestroyAction)($mockExam);
    }

    private function queryException(string $constraint, int $driverCode): QueryException
    {
        $message = 'Cannot delete or update a parent row: a foreign key constraint fails ('.$constraint.')';
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'delete from mock_exams', [], $previous);
    }
}
