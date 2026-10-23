<?php
declare(strict_types=1);
namespace App\UseCases\MeetingReminder;
use App\UseCases\MeetingReminder\DTOs\MeetingReminderChannelClaim;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class ClaimMeetingReminderDispatchAction
{
    public function __invoke(string $dispatchId, string $channel): ?MeetingReminderChannelClaim
    {
        if (! in_array($channel, ['database', 'mail'], true)) return null;
        $reference = MeetingReminderDispatch::query()->find($dispatchId);
        if ($reference === null) return null;
        return DB::transaction(function () use ($dispatchId, $channel, $reference): ?MeetingReminderChannelClaim {
            $meeting = Meeting::whereKey($reference->meeting_id)->lockForUpdate()->first();
            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved) return null;
            $dispatch = MeetingReminderDispatch::whereKey($dispatchId)->lockForUpdate()->first();
            if ($dispatch === null || $dispatch->meeting_id !== $meeting->id || $dispatch->isLegacy() || ! $dispatch->isQueueable()) return null;
            $statusColumn = $channel.'_status';
            $tokenColumn = $channel.'_dispatch_token';
            $startedColumn = $channel.'_processing_started_at';
            if ($dispatch->{$statusColumn} !== 'pending') return null;
            if (CarbonImmutable::instance($meeting->scheduled_at)->setTimezone('Asia/Tokyo')->lessThanOrEqualTo(CarbonImmutable::now('Asia/Tokyo'))) return null;
            $token = $dispatch->{$tokenColumn} ?: (string) Str::uuid();
            $dispatch->{$tokenColumn} = $token;
            $dispatch->{$statusColumn} = 'processing';
            $dispatch->{$startedColumn} = now();
            $dispatch->save();
            return new MeetingReminderChannelClaim($dispatch->fresh(), $channel, $token);
        });
    }
}
