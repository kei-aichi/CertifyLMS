<?php

declare(strict_types=1);

namespace App\Jobs\Meeting;

use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DispatchMeetingReminderNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $dispatchId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('meeting-reminder-dispatch:'.$this->dispatchId))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(ClaimMeetingReminderDispatchAction $claim): void
    {
        foreach (['database', 'mail'] as $channel) {
            $dispatch = MeetingReminderDispatch::query()->find($this->dispatchId);
            if ($dispatch === null || $dispatch->isLegacy() || $dispatch->{$channel.'_status'} !== 'pending') continue;

            $result = $claim($this->dispatchId, $channel);
            if ($result === null) continue;

            $attemptUuid = (string) Str::uuid();
            $attempt = MeetingReminderQueueAttempt::create([
                'queue_attempt_uuid' => $attemptUuid,
                'dispatch_id' => $this->dispatchId,
                'channel' => $channel,
                'dispatch_token' => $result->token,
                'state' => 'preparing',
                'queue_name' => config('queue.connections.database.queue', 'default'),
            ]);

            try {
                if ($channel === 'database') {
                    MeetingReminderDatabaseNotificationJob::dispatch($this->dispatchId, $result->token, $attemptUuid);
                } else {
                    MeetingReminderMailNotificationJob::dispatch($this->dispatchId, $result->token, $attemptUuid);
                }

                MeetingReminderQueueAttempt::query()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => 'enqueued', 'queued_at' => now(), 'updated_at' => now()]);
                MeetingReminderDispatch::query()
                    ->whereKey($this->dispatchId)
                    ->where('legacy', false)
                    ->where($channel.'_status', 'processing')
                    ->where($channel.'_dispatch_token', $result->token)
                    ->update([$channel.'_queued_at' => now()]);
            } catch (\Throwable $exception) {
                MeetingReminderQueueAttempt::query()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => 'unknown', 'failure_reason' => $exception->getMessage(), 'updated_at' => now()]);
                Log::error('Meeting reminder notification job enqueue failed.', [
                    'dispatch_id' => $this->dispatchId,
                    'channel' => $channel,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
