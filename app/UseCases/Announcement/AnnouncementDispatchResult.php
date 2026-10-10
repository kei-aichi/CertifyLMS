<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;

final readonly class AnnouncementDispatchResult
{
    public function __construct(
        public Announcement $announcement,
        public bool $alreadyExists = false,
    ) {}
}
