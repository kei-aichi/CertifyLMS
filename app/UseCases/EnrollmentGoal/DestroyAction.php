<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        DB::transaction(function () use ($goal): void {
            EnrollmentGoal::query()->lockForUpdate()->findOrFail($goal->id)->delete();
        });
    }
}
