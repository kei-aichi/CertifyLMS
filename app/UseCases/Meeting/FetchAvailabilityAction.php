<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class FetchAvailabilityAction
{
    public function __construct(private readonly MeetingAvailabilityService $availabilityService) {}

    /** @return array{date: Carbon, slots: Collection<int, array<string, mixed>>} */
    public function __invoke(Enrollment $enrollment, string $date): array
    {
        $parsedDate = Carbon::parse($date);
        $slots = $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $parsedDate,
        );

        return ['date' => $parsedDate, 'slots' => $slots];
    }
}
