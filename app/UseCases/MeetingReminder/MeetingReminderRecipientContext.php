<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\Models\Meeting;
use App\Models\User;
final readonly class MeetingReminderRecipientContext
{
    public function __construct(public Meeting $meeting, public User $recipient) {}
}
