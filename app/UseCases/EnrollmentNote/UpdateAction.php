<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    /** @param array{body: string} $validated */
    public function __invoke(EnrollmentNote $note, array $validated): EnrollmentNote
    {
        return DB::transaction(function () use ($note, $validated): EnrollmentNote {
            $note = EnrollmentNote::query()->lockForUpdate()->findOrFail($note->id);
            $note->update(['body' => $validated['body']]);

            return $note->refresh();
        });
    }
}
