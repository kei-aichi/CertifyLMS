<?php

declare(strict_types=1);

namespace App\UseCases\Meeting\ViewModels;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class StudentMeetingsIndexViewModel
{
    public function __construct(
        public LengthAwarePaginator $meetings,
        public string $filter,
        public int $meetingsRemaining,
    ) {}
}
