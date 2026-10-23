<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class AcquireQueueDispatchAction
{
    private const TZ = 'Asia/Tokyo';
    public function __invoke(string $meetingId, string $window, string $recipientId): AcquireQueueDispatchResult
    {
        if (! in_array($window, ['eve', 'one_hour_before'], true)) throw new InvalidArgumentException('Invalid meeting reminder window.');
        return DB::transaction(function () use ($meetingId, $window, $recipientId): AcquireQueueDispatchResult {
            $meeting = Meeting::whereKey($meetingId)->lockForUpdate()->first();
            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) return AcquireQueueDispatchResult::newNotEligible();
            $now = CarbonImmutable::now(self::TZ); $at = CarbonImmutable::instance($meeting->scheduled_at)->setTimezone(self::TZ);
            if ($at->lessThanOrEqualTo($now) || ! $this->within($now, $at, $window)) return AcquireQueueDispatchResult::newNotEligible();
            $role = match ($recipientId) { $meeting->student_id => UserRole::Student, $meeting->coach_id => UserRole::Coach, default => null };
            $recipient = $role === null ? null : User::withTrashed()->find($recipientId);
            if ($recipient === null || $recipient->trashed() || $recipient->role !== $role || $recipient->status !== UserStatus::InProgress) return AcquireQueueDispatchResult::newNotEligible();
            $existing = MeetingReminderDispatch::where(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id])->lockForUpdate()->first();
            if ($existing) return $this->classify($existing);
            try { return AcquireQueueDispatchResult::newAcquired(MeetingReminderDispatch::create(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id,'legacy'=>false,'database_status'=>'pending','mail_status'=>'pending'])); }
            catch (QueryException $e) { if ((string)($e->errorInfo[1] ?? '') !== '1062') throw $e; $existing=MeetingReminderDispatch::where(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id])->lockForUpdate()->firstOrFail(); return $this->classify($existing); }
        });
    }
    private function classify(MeetingReminderDispatch $d): AcquireQueueDispatchResult
    { if ($d->isLegacy()) return AcquireQueueDispatchResult::duplicateLegacy($d); if ($d->database_status === 'succeeded' && $d->mail_status === 'succeeded') return AcquireQueueDispatchResult::duplicate($d); return AcquireQueueDispatchResult::resumePending($d); }
    private function within(CarbonImmutable $now, CarbonImmutable $at, string $window): bool
    { if ($window === 'eve') { $s=$now->startOfDay()->setTime(18,0); return $now->greaterThanOrEqualTo($s)&&$now->lessThan($s->addMinute())&&$at->greaterThanOrEqualTo($now->startOfDay()->addDay())&&$at->lessThan($now->startOfDay()->addDays(2)); } $s=$now->startOfMinute()->addHour(); return $at->greaterThanOrEqualTo($s)&&$at->lessThan($s->addMinute()); }
}
