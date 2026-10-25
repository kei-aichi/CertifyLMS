<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Certification;

use App\Exceptions\Certification\CertificationNotDeletableException;
use App\Models\Certification;
use App\UseCases\Certification\DestroyAction;
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
     * @dataProvider restrictedForeignKeyProvider
     */
    public function test_converts_restricted_foreign_key_violation_to_conflict(
        string $constraint,
    ): void {
        $queryException = $this->queryException($constraint, 1451);
        $this->mockTransactionToThrow($queryException);

        try {
            (new DestroyAction)(Mockery::mock(Certification::class));
            $this->fail('CertificationNotDeletableExceptionが発生するはずです。');
        } catch (CertificationNotDeletableException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                '関連する受講履歴・質問・教材データが存在するため、削除できません。',
                $exception->getMessage(),
            );
            $this->assertSame($queryException, $exception->getPrevious());
        }
    }

    public static function restrictedForeignKeyProvider(): array
    {
        return [
            ['parts_certification_id_foreign'],
            ['enrollments_certification_id_foreign'],
            ['certificates_certification_id_foreign'],
            ['mock_exams_certification_id_foreign'],
            ['qa_threads_certification_id_foreign'],
            ['question_categories_certification_id_foreign'],
            ['announcements_target_certification_id_foreign'],
        ];
    }

    public function test_rethrows_unrelated_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('users_id_foreign', 1451);
        $this->mockTransactionToThrow($queryException);

        try {
            (new DestroyAction)(Mockery::mock(Certification::class));
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    public function test_rethrows_non_1451_restricted_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('parts_certification_id_foreign', 1452);
        $this->mockTransactionToThrow($queryException);

        try {
            (new DestroyAction)(Mockery::mock(Certification::class));
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    private function mockTransactionToThrow(QueryException $queryException): void
    {
        DB::shouldReceive('transaction')->once()->andThrow($queryException);
    }

    private function queryException(string $constraint, int $driverCode): QueryException
    {
        $message = 'Cannot delete or update a parent row: a foreign key constraint fails ('.$constraint.')';
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'delete from certifications', [], $previous);
    }
}
