<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        return DB::transaction(function () use ($goal): EnrollmentGoal {
            $goal = EnrollmentGoal::query()->lockForUpdate()->findOrFail($goal->id);
            $goal->update(['achieved_at' => null]);

            return $goal->refresh();
        });
    }
}
