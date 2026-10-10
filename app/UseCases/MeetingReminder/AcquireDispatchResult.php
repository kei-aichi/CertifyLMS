<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Models\MeetingReminderDispatch;

final readonly class AcquireDispatchResult
{
    private function __construct(
        public string $status,
        public ?MeetingReminderDispatch $dispatch = null,
    ) {}

    public static function acquiredResult(MeetingReminderDispatch $dispatch): self
    {
        return new self('acquired', $dispatch);
    }

    public static function notEligibleResult(): self
    {
        return new self('not_eligible');
    }

    public static function duplicateResult(): self
    {
        return new self('duplicate');
    }

    public function acquired(): bool
    {
        return $this->status === 'acquired';
    }

    public function notEligible(): bool
    {
        return $this->status === 'not_eligible';
    }

    public function duplicate(): bool
    {
        return $this->status === 'duplicate';
    }
}
