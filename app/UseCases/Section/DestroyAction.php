<?php

declare(strict_types=1);

namespace App\UseCases\Section;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

/**
 * Section の SoftDelete ユースケース。Draft 状態のみ削除可、Published は削除拒否。
 */
final class DestroyAction
{
    /**
     * @throws ContentNotDeletableException
     */
    public function __invoke(Section $section): void
    {
        if ($section->status !== ContentStatus::Draft) {
            throw ContentNotDeletableException::forSection();
        }

        $hasRelatedData = $section->images()->exists()
            || $section->questions()->exists()
            || $section->learningSessions()->exists()
            || $section->progresses()->exists()
            || $section->questions()->whereHas('sectionQuestionAnswers')->exists()
            || $section->questions()->whereHas('sectionQuestionAttempts')->exists();

        if ($hasRelatedData) {
            throw ContentNotDeletableException::forSectionRelatedData();
        }

        try {
            DB::transaction(fn () => $section->delete());
        } catch (QueryException $exception) {
            $constraints = [
                'section_images_section_id_foreign',
                'learning_sessions_section_id_foreign',
                'section_progresses_section_id_foreign',
                'section_question_answers_section_question_id_foreign',
                'section_question_attempts_section_question_id_foreign',
            ];

            $isTargetConstraint = collect($constraints)
                ->contains(fn (string $constraint) => str_contains($exception->getMessage(), $constraint));

            if ((int) ($exception->errorInfo[1] ?? 0) === 1451 && $isTargetConstraint) {
                throw ContentNotDeletableException::forSectionRelatedData();
            }

            throw $exception;
        }
    }
}
