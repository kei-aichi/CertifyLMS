<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\Models\MeetingReminderDispatch;
final readonly class AcquireQueueDispatchResult
{
    private function __construct(public string $status, public ?MeetingReminderDispatch $dispatch = null) {}
    public static function newAcquired(MeetingReminderDispatch $d): self { return new self('acquired', $d); }
    public static function duplicateLegacy(MeetingReminderDispatch $d): self { return new self('duplicate_legacy', $d); }
    public static function resumePending(MeetingReminderDispatch $d): self { return new self('resume_pending', $d); }
    public static function duplicate(MeetingReminderDispatch $d): self { return new self('duplicate', $d); }
    public static function newNotEligible(): self { return new self('not_eligible'); }
    public function is(string $status): bool { return $this->status === $status; }
    public function acquired(): bool { return $this->status === 'acquired'; }
    public function notEligible(): bool { return $this->status === 'not_eligible'; }
}
