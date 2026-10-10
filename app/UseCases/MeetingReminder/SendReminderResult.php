<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

final readonly class SendReminderResult
{
    public function __construct(
        public string $acquisitionStatus,
        public string $databaseStatus = 'not_sent',
        public string $mailStatus = 'not_sent',
        public ?string $notificationId = null,
    ) {}

    public function acquired(): bool
    {
        return $this->acquisitionStatus === 'acquired';
    }

    public function failed(): bool
    {
        return in_array('failed', [$this->databaseStatus, $this->mailStatus], true);
    }
}
