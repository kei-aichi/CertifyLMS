<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Database\Eloquent\Collection;

final class ShowCheckoutAction
{
    private const MINIMUM_AMOUNT = 50;

    /** @return Collection<int, MeetingPack> */
    public function __invoke(): Collection
    {
        return MeetingPack::query()
            ->where('status', MeetingPackStatus::Published)
            ->where('price', '>=', self::MINIMUM_AMOUNT)
            ->where('meeting_count', '>=', 1)
            ->ordered()
            ->get();
    }
}
