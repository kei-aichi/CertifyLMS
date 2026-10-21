<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\ViewModels\StudentMeetingsIndexViewModel;

final class ListStudentMeetingsAction
{
    public function __construct(private readonly MeetingQuotaService $meetingQuota) {}

    public function __invoke(User $student, string $filter = 'upcoming'): StudentMeetingsIndexViewModel
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };

        return new StudentMeetingsIndexViewModel(
            meetings: $meetings,
            filter: $filter,
            meetingsRemaining: $this->meetingQuota->remaining($student),
        );
    }
}
