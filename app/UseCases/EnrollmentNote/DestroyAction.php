<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(EnrollmentNote $note): void
    {
        DB::transaction(function () use ($note): void {
            EnrollmentNote::query()->lockForUpdate()->findOrFail($note->id)->delete();
        });
    }
}
