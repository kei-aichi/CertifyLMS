<?php

declare(strict_types=1);

namespace App\Jobs\Announcement;

use App\Models\AnnouncementRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DispatchAnnouncementRecipientsJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public readonly string $announcementId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('announcement-dispatch:'.$this->announcementId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        AnnouncementRecipient::query()
            ->where('announcement_id', $this->announcementId)
            ->where(function ($query): void {
                $query->where('database_status', '!=', 'succeeded')
                    ->orWhere('mail_status', '!=', 'succeeded');
            })
            ->orderBy('id')
            ->chunkById(100, function ($recipients): void {
                foreach ($recipients as $recipient) {
                    $this->dispatchChannel($recipient->id, 'database');
                    $this->dispatchChannel($recipient->id, 'mail');
                }
            });

        AggregateAnnouncementStatusJob::dispatch($this->announcementId);
    }

    private function dispatchChannel(string $recipientId, string $channel): void
    {
        $jobClass = $channel === 'database'
            ? AnnouncementDatabaseNotificationJob::class
            : AnnouncementMailNotificationJob::class;

        // The channel Jobs are introduced in the following implementation step.
        // Do not mark a row queued while the executable Job is unavailable.
        if (! class_exists($jobClass)) {
            Log::debug('Announcement notification Job is not available yet.', [
                'announcement_recipient_id' => $recipientId,
                'channel' => $channel,
            ]);

            return;
        }

        $tokenColumn = $channel.'_dispatch_token';
        $claimedColumn = $channel.'_claimed_at';
        $queuedColumn = $channel.'_queued_at';
        $statusColumn = $channel.'_status';

        $claim = DB::transaction(function () use ($recipientId, $tokenColumn, $claimedColumn, $queuedColumn, $statusColumn): ?array {
            /** @var AnnouncementRecipient|null $recipient */
            $recipient = AnnouncementRecipient::query()
                ->whereKey($recipientId)
                ->lockForUpdate()
                ->first();

            if ($recipient === null || $recipient->{$statusColumn} === 'succeeded') {
                return null;
            }

            $token = $recipient->{$tokenColumn} ?: (string) Str::uuid();
            $recipient->forceFill([
                $tokenColumn => $token,
                $claimedColumn => now(),
            ])->save();

            return [$token, $recipient->{$queuedColumn} !== null];
        });

        if ($claim === null || $claim[1]) {
            return;
        }

        $jobClass::dispatch($recipientId, $claim[0]);

        AnnouncementRecipient::query()
            ->whereKey($recipientId)
            ->where($tokenColumn, $claim[0])
            ->whereNull($queuedColumn)
            ->update([$queuedColumn => now()]);
    }
}
