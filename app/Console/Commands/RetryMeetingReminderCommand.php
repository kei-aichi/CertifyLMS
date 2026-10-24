<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\Meeting\MeetingReminderDatabaseNotificationJob;
use App\Jobs\Meeting\MeetingReminderMailNotificationJob;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\MeetingReminderQueueAttempt;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RetryMeetingReminderCommand extends Command
{
    protected $signature = 'meeting-reminders:retry {dispatch_id} {channel : database or mail} {failed_uuid}';
    protected $description = '指定した面談リマインダーのfailed Jobだけを新しい再送サイクルへ投入する。';

    public function handle(): int
    {
        $dispatchId = (string) $this->argument('dispatch_id');
        $channel = (string) $this->argument('channel');
        $failedUuid = (string) $this->argument('failed_uuid');
        if (! in_array($channel, ['database', 'mail'], true)) return $this->failure('channel must be database or mail.');

        $failed = DB::table('failed_jobs')->where('uuid', $failedUuid)->first(['uuid', 'payload']);
        if ($failed === null) return $this->failure('failed job was not found.');
        $meta = $this->metadata((string) $failed->payload);
        if ($meta === null || $meta['dispatch_id'] !== $dispatchId || $meta['channel'] !== $channel) {
            return $this->failure('failed job metadata does not match the requested dispatch and channel.');
        }

        try {
            $result = DB::transaction(function () use ($dispatchId, $channel, $meta): array {
                $dispatch = MeetingReminderDispatch::query()->lockForUpdate()->findOrFail($dispatchId);
                $meeting = Meeting::query()->lockForUpdate()->find($dispatch->meeting_id);
                $recipient = User::query()->find($dispatch->recipient_id);
                $expectedRole = $recipient?->id === $meeting?->student_id ? UserRole::Student : ($recipient?->id === $meeting?->coach_id ? UserRole::Coach : null);
                if ($dispatch->legacy || $dispatch->{$channel.'_status'} !== 'failed' || $meeting === null || $meeting->status !== MeetingStatus::Reserved || $meeting->scheduled_at->lessThanOrEqualTo(now()) || $recipient === null || $recipient->trashed() || $recipient->status !== UserStatus::InProgress || $expectedRole === null || $recipient->role !== $expectedRole) {
                    throw new RuntimeException('dispatch is not eligible for manual retry.');
                }
                if ($dispatch->{$channel.'_dispatch_token'} !== $meta['dispatch_token']) throw new RuntimeException('dispatch token mismatch.');
                $oldAttempt = MeetingReminderQueueAttempt::query()->where('queue_attempt_uuid', $meta['queue_attempt_uuid'])->where('dispatch_id', $dispatchId)->where('channel', $channel)->where('dispatch_token', $meta['dispatch_token'])->where('state', 'failed')->first();
                if ($oldAttempt === null) throw new RuntimeException('queue attempt metadata does not match.');
                $newerAttemptExists = MeetingReminderQueueAttempt::query()
                    ->where('dispatch_id', $dispatchId)
                    ->where('channel', $channel)
                    ->where(function ($query) use ($oldAttempt): void {
                        $query->where('created_at', '>', $oldAttempt->created_at)
                            ->orWhere(function ($sameTimestamp) use ($oldAttempt): void {
                                $sameTimestamp->where('created_at', $oldAttempt->created_at)
                                    ->where('id', '>', $oldAttempt->id);
                            });
                    })
                    ->exists();
                if ($newerAttemptExists) throw new RuntimeException('failed job was already recovered; use the latest failed Job UUID.');
                $cycle = ((int) NotificationDeliveryAttempt::query()->where('event_key', 'meeting_reminder:'.$dispatch->meeting_id.':'.$dispatch->window)->where('recipient_id', $dispatch->recipient_id)->where('channel', $channel)->max('cycle')) + 1;
                $token = (string) Str::uuid();
                $attemptUuid = (string) Str::uuid();
                $dispatch->{$channel.'_status'} = 'processing';
                $dispatch->{$channel.'_dispatch_token'} = $token;
                $dispatch->{$channel.'_queued_at'} = null;
                $dispatch->{$channel.'_processing_started_at'} = null;
                $dispatch->{$channel.'_completed_at'} = null;
                $dispatch->save();
                $attempt = MeetingReminderQueueAttempt::create(['queue_attempt_uuid' => $attemptUuid, 'dispatch_id' => $dispatchId, 'channel' => $channel, 'dispatch_token' => $token, 'state' => 'preparing', 'queue_name' => config('queue.connections.database.queue', 'default')]);
                return [$dispatch, $attempt, $token, $attemptUuid, $cycle];
            });
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        }

        [$dispatch, $attempt, $token, $attemptUuid, $cycle] = $result;
        try {
            $job = $channel === 'database'
                ? new MeetingReminderDatabaseNotificationJob($dispatch->id, $token, $attemptUuid, $cycle)
                : new MeetingReminderMailNotificationJob($dispatch->id, $token, $attemptUuid, $cycle);
            dispatch($job);
            MeetingReminderQueueAttempt::query()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => 'enqueued', 'queued_at' => now()]);
            MeetingReminderDispatch::query()->whereKey($dispatch->id)->where($channel.'_status', 'processing')->where($channel.'_dispatch_token', $token)->update([$channel.'_queued_at' => now()]);
        } catch (\Throwable $e) {
            MeetingReminderQueueAttempt::query()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => 'unknown', 'failure_reason' => $e->getMessage()]);
            MeetingReminderDispatch::query()->whereKey($dispatch->id)->where($channel.'_status', 'processing')->where($channel.'_dispatch_token', $token)->update([$channel.'_status' => 'failed', $channel.'_completed_at' => now()]);
            return $this->failure('retry enqueue failed: '.$e->getMessage());
        }
        if ($channel === 'mail') $this->warn('Mail delivery may have succeeded before the previous state update; manual retry can send a duplicate email.');
        $this->info('manual retry queued: dispatch='.$dispatch->id.' channel='.$channel.' cycle='.$cycle);
        return self::SUCCESS;
    }

    /** @return array{dispatch_id:string,channel:string,dispatch_token:string,queue_attempt_uuid:string}|null */
    private function metadata(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        $meta = is_array($decoded) ? ($decoded['meeting_reminder'] ?? null) : null;
        if (! is_array($meta) || ! is_string($meta['dispatch_id'] ?? null) || ! is_string($meta['channel'] ?? null) || ! is_string($meta['dispatch_token'] ?? null) || ! is_string($meta['queue_attempt_uuid'] ?? null)) return null;
        return $meta;
    }

    private function failure(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
