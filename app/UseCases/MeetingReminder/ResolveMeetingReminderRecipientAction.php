<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\UseCases\MeetingReminder\DTOs\MeetingReminderRecipientContext;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
final class ResolveMeetingReminderRecipientAction
{
    private const TZ = 'Asia/Tokyo';
    public function __invoke(string $meetingId, string $window, string $recipientId): ?MeetingReminderRecipientContext
    {
        if (! in_array($window, ['eve', 'one_hour_before'], true)) throw new InvalidArgumentException('Invalid meeting reminder window.');
        $meeting = Meeting::whereKey($meetingId)->lockForUpdate()->first();
        if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) return null;
        $now = CarbonImmutable::now(self::TZ); $at = CarbonImmutable::instance($meeting->scheduled_at)->setTimezone(self::TZ);
        if ($at->lessThanOrEqualTo($now) || ! $this->within($now, $at, $window)) return null;
        $role = match ($recipientId) { $meeting->student_id => UserRole::Student, $meeting->coach_id => UserRole::Coach, default => null };
        $recipient = $role === null ? null : User::withTrashed()->find($recipientId);
        if ($recipient === null || $recipient->trashed() || $recipient->role !== $role || $recipient->status !== UserStatus::InProgress) return null;
        return new MeetingReminderRecipientContext($meeting, $recipient);
    }
    private function within(CarbonImmutable $now, CarbonImmutable $at, string $window): bool
    { if ($window === 'eve') { $s=$now->startOfDay()->setTime(18,0); return $now->greaterThanOrEqualTo($s)&&$now->lessThan($s->addMinute())&&$at->greaterThanOrEqualTo($now->startOfDay()->addDay())&&$at->lessThan($now->startOfDay()->addDays(2)); } $s=$now->startOfMinute()->addHour(); return $at->greaterThanOrEqualTo($s)&&$at->lessThan($s->addMinute()); }
}
