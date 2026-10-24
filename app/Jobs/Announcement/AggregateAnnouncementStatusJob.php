<?php

declare(strict_types=1);

namespace App\Jobs\Announcement;

use App\Enums\AnnouncementDispatchStatus;
use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class AggregateAnnouncementStatusJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public readonly string $announcementId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('announcement-aggregate:'.$this->announcementId))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $announcement = Announcement::query()->whereKey($this->announcementId)->lockForUpdate()->first();
            if ($announcement === null) return;
            $recipients = $announcement->recipients();
            $hasRecipients = $recipients->exists();
            $hasFailed = (clone $recipients)->where(fn ($q) => $q->where('database_status', 'failed')->orWhere('mail_status', 'failed'))->exists();
            $hasIncomplete = (clone $recipients)->where(fn ($q) => $q->where('database_status', '!=', 'succeeded')->orWhere('mail_status', '!=', 'succeeded'))->exists();
            $status = ! $hasRecipients || (! $hasIncomplete && ! $hasFailed)
                ? AnnouncementDispatchStatus::Succeeded
                : ($hasFailed ? AnnouncementDispatchStatus::Failed : AnnouncementDispatchStatus::Processing);
            if ($announcement->dispatch_status !== $status) $announcement->forceFill(['dispatch_status' => $status])->save();
        });
    }
}
