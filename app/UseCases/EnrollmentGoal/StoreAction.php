<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    /** @param array{title: string, description?: ?string, target_date?: ?string} $validated */
    public function __invoke(Enrollment $enrollment, array $validated): EnrollmentGoal
    {
        return DB::transaction(fn () => $enrollment->goals()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
            'achieved_at' => null,
        ]));
    }
}
