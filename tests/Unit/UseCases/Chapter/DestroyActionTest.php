<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Chapter;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Chapter;
use App\UseCases\Chapter\DestroyAction;
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

    public function test_converts_sections_foreign_key_violation_to_business_exception(): void
    {
        $queryException = $this->queryException('sections_chapter_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('ContentNotDeletableExceptionが発生するはずです。');
        } catch (ContentNotDeletableException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'Chapterに子データまたは関連データが存在するため削除できません。',
                $exception->getMessage(),
            );
        }
    }

    public function test_rethrows_unrelated_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('chapters_part_id_foreign', 1451);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    public function test_rethrows_non_1451_sections_foreign_key_violation_unchanged(): void
    {
        $queryException = $this->queryException('sections_chapter_id_foreign', 1452);

        try {
            $this->invokeActionWith($queryException);
            $this->fail('QueryExceptionが再throwされるはずです。');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
    }

    private function invokeActionWith(QueryException $queryException): void
    {
        $chapter = Mockery::mock(Chapter::class)->makePartial();
        $chapter->shouldReceive('getAttribute')
            ->with('status')
            ->andReturn(ContentStatus::Draft);

        $sections = Mockery::mock(HasMany::class);
        $sections->shouldReceive('exists')->once()->andReturn(false);
        $chapter->shouldReceive('sections')->once()->andReturn($sections);

        DB::shouldReceive('transaction')->once()->andThrow($queryException);

        (new DestroyAction)($chapter);
    }

    private function queryException(string $constraint, int $driverCode): QueryException
    {
        $message = 'Cannot delete or update a parent row: a foreign key constraint fails ('.$constraint.')';
        $previous = new PDOException($message);
        $previous->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'delete from chapters', [], $previous);
    }
}
