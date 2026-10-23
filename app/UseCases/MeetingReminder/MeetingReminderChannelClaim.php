<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\Models\MeetingReminderDispatch;
final readonly class MeetingReminderChannelClaim
{
    public function __construct(public MeetingReminderDispatch $dispatch, public string $channel, public string $token) {}
}
