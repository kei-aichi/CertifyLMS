<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Part;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Part;
use App\UseCases\Part\DestroyAction;
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

    public function test_converts_chapters_foreign_key_violation_to_business_exception(): void
    {
        $queryException = $this->queryException('chapters_part_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('ContentNotDeletableExceptionが発生するはずです。');
        } catch (ContentNotDeletableException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'Partに子データまたは関連データが存在するため削除できません。',
                $exception->getMessage(),
            );
        }
    }

    public function test_rethrows_unrelated_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('parts_certification_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    public function test_rethrows_non_1451_chapters_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('chapters_part_id_foreign', 1452);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    private function invokeActionWith(QueryException $queryException): void
    {
        $part = Mockery::mock(Part::class)->makePartial();
        $part->shouldReceive('getAttribute')
            ->with('status')
            ->andReturn(ContentStatus::Draft);

        $chapters = Mockery::mock(HasMany::class);
        $chapters->shouldReceive('exists')->once()->andReturn(false);
        $part->shouldReceive('chapters')->once()->andReturn($chapters);

        DB::shouldReceive('transaction')->once()->andThrow($queryException);

        (new DestroyAction)($part);
    }

    private function queryException(string $constraint, int $driverCode): QueryException
    {
        $message = 'Cannot delete or update a parent row: a foreign key constraint fails ('.$constraint.')';
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'delete from parts', [], $previous);
    }
}
