<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\Models\MeetingReminderDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
final class AcquireQueueDispatchAction
{
    public function __invoke(string $meetingId, string $window, string $recipientId): AcquireQueueDispatchResult
    {
        return DB::transaction(function () use ($meetingId, $window, $recipientId): AcquireQueueDispatchResult {
            $context = (new ResolveMeetingReminderRecipient)($meetingId, $window, $recipientId);
            if ($context === null) return AcquireQueueDispatchResult::newNotEligible();
            $meeting = $context->meeting; $recipient = $context->recipient;
            $existing = MeetingReminderDispatch::where(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id])->lockForUpdate()->first();
            if ($existing) return $this->classify($existing);
            try { return AcquireQueueDispatchResult::newAcquired(MeetingReminderDispatch::create(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id,'legacy'=>false,'database_status'=>'pending','mail_status'=>'pending'])); }
            catch (QueryException $e) { if ((string)($e->errorInfo[1] ?? '') !== '1062') throw $e; $existing=MeetingReminderDispatch::where(['meeting_id'=>$meeting->id,'window'=>$window,'recipient_id'=>$recipient->id])->lockForUpdate()->firstOrFail(); return $this->classify($existing); }
        });
    }
    private function classify(MeetingReminderDispatch $d): AcquireQueueDispatchResult
    { if ($d->isLegacy()) return AcquireQueueDispatchResult::duplicateLegacy($d); if ($d->database_status === 'succeeded' && $d->mail_status === 'succeeded') return AcquireQueueDispatchResult::duplicate($d); return AcquireQueueDispatchResult::resumePending($d); }
}
