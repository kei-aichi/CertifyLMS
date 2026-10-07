<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    /** @param array{title: string, description?: ?string, target_date?: ?string} $validated */
    public function __invoke(EnrollmentGoal $goal, array $validated): EnrollmentGoal
    {
        return DB::transaction(function () use ($goal, $validated): EnrollmentGoal {
            $goal = EnrollmentGoal::query()->lockForUpdate()->findOrFail($goal->id);
            $goal->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'target_date' => $validated['target_date'] ?? null,
            ]);

            return $goal->refresh();
        });
    }
}
