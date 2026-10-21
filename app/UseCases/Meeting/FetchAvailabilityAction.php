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

    /** @return Collection<int, array<string, mixed>> */
    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        return $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );
    }
}
