<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Announcement\AggregateAnnouncementStatusJob;
use App\Jobs\Announcement\AnnouncementDatabaseNotificationJob;
use App\Jobs\Announcement\AnnouncementMailNotificationJob;
use App\Jobs\Announcement\DispatchAnnouncementRecipientsJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverAnnouncementQueueCommand extends Command
{
    protected $signature = 'announcements';

    protected $description = '管理者お知らせの未投入・消失したQueue Jobを安全に復旧する。';

    private const CLAIM_TIMEOUT_SECONDS = 240;

    public function handle(): int
    {
        $announcements = Announcement::query()
            ->whereIn('dispatch_status', ['processing', 'failed'])
            ->whereHas('recipients')
            ->get();

        $recovered = 0;
        $skipped = 0;

        foreach ($announcements as $announcement) {
            $hasPending = $announcement->recipients()
                ->where(function ($query): void {
                    $query->where('database_status', 'pending')
                        ->orWhere('mail_status', 'pending');
                })->exists();

            if ($hasPending) {
                DispatchAnnouncementRecipientsJob::dispatch($announcement->id);
                $recovered++;
            }

            foreach ($announcement->recipients()->get() as $recipient) {
                foreach (['database', 'mail'] as $channel) {
                    $result = $this->recoverChannel($recipient, $channel);
                    $result ? $recovered++ : $skipped++;
                }
            }

            AggregateAnnouncementStatusJob::dispatch($announcement->id);
        }

        $this->info("Announcement queue recovery completed. recovered={$recovered}, skipped={$skipped}");

        return self::SUCCESS;
    }

    private function recoverChannel(AnnouncementRecipient $recipient, string $channel): bool
    {
        $statusColumn = $channel.'_status';
        $tokenColumn = $channel.'_dispatch_token';
        $claimedColumn = $channel.'_claimed_at';
        $queuedColumn = $channel.'_queued_at';

        if ($recipient->{$statusColumn} === 'succeeded') {
            return false;
        }

        $token = $recipient->{$tokenColumn};
        if ($token === null) {
            return false;
        }

        $attempts = (int) NotificationDeliveryAttempt::query()
            ->where('event_key', 'announcement:'.$recipient->announcement_id)
            ->where('recipient_id', $recipient->recipient_id)
            ->where('channel', $channel)
            ->value('attempts');

        $expectedJob = $channel === 'database'
            ? AnnouncementDatabaseNotificationJob::class
            : AnnouncementMailNotificationJob::class;

        if ($attempts >= 3 || $this->hasFailedJob($token, $expectedJob, $recipient->id, $channel) || $this->hasLiveQueueJob($token, $expectedJob, $recipient->id, $channel)) {
            return false;
        }

        $claimedAt = $recipient->{$claimedColumn};
        $queuedAt = $recipient->{$queuedColumn};
        $processingColumn = $channel.'_processing_started_at';
        $processingStartedAt = $recipient->{$processingColumn};
        // The most recent lifecycle timestamp is the recovery clock.  A job
        // that is actively processing must not be reclaimed merely because
        // its queued_at value is old.
        $reference = collect([$claimedAt, $queuedAt, $processingStartedAt])
            ->filter()
            ->sortByDesc(fn ($value) => $value->getTimestamp())
            ->first();
        if ($reference === null || $reference->gt(now()->subSeconds(self::CLAIM_TIMEOUT_SECONDS))) {
            return false;
        }

        $claimed = DB::transaction(function () use ($recipient, $channel, $claimedColumn, $queuedColumn): bool {
            $locked = AnnouncementRecipient::query()->whereKey($recipient->id)->lockForUpdate()->first();
            if ($locked === null || $locked->{$channel.'_status'} === 'succeeded') return false;
            $locked->forceFill([$claimedColumn => now()])->save();
            return true;
        });

        if (! $claimed) return false;

        if ($channel === 'database') {
            AnnouncementDatabaseNotificationJob::dispatch($recipient->id, $token);
        } else {
            AnnouncementMailNotificationJob::dispatch($recipient->id, $token);
        }

        AnnouncementRecipient::query()->whereKey($recipient->id)->whereNull($queuedColumn)->update([$queuedColumn => now()]);
        return true;
    }

    private function hasLiveQueueJob(string $token, string $expectedJob, string $recipientRowId, string $channel): bool
    {
        $rows = DB::table('jobs')->get(['payload', 'available_at', 'reserved_at']);
        foreach ($rows as $row) {
            $match = $this->payloadMatchesJob((string) $row->payload, $token, $expectedJob, $recipientRowId, $channel);
            if ($match === true || $match === null) return true;
        }
        return false;
    }

    private function hasFailedJob(string $token, string $expectedJob, string $recipientRowId, string $channel): bool
    {
        $rows = DB::table('failed_jobs')->get(['payload']);
        foreach ($rows as $row) {
            $match = $this->payloadMatchesJob((string) $row->payload, $token, $expectedJob, $recipientRowId, $channel);
            if ($match === true || $match === null) return true;
        }
        return false;
    }

    /**
     * @return bool|null true=matching job, false=valid non-match, null=ambiguous
     */
    private function payloadMatchesJob(string $payload, string $token, string $expectedJob, string $recipientRowId, string $channel): ?bool
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) return null;
        if (($decoded['data']['commandName'] ?? null) !== $expectedJob) return false;

        $command = $decoded['data']['command'] ?? null;
        if (! is_string($command)) return null;

        // Laravel stores the command as a PHP serialized string. Parse only
        // its scalar property representation; never unserialize untrusted data.
        $classPattern = preg_quote($expectedJob, '/');
        $pattern = '/^O:\d+:"'.$classPattern.'":\d+:\{'
            .'s:\d+:"recipientRowId";s:(\d+):"([^"]*)";'
            .'s:\d+:"dispatchToken";s:(\d+):"([^"]*)";\}$/';
        if (preg_match($pattern, $command, $matches) !== 1) return null;
        if ((int) $matches[1] !== strlen($matches[2]) || (int) $matches[3] !== strlen($matches[4])) return null;

        $expectedChannel = $channel === 'database'
            ? AnnouncementDatabaseNotificationJob::class
            : AnnouncementMailNotificationJob::class;

        return ($decoded['displayName'] ?? null) === $expectedChannel
            && $matches[2] === $recipientRowId
            && $matches[4] === $token;
    }
}
