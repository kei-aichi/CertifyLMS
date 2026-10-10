<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchAnnouncementAction
{
    public function __construct(private readonly ResolveRecipientsAction $resolveRecipients) {}

    /** @param array<string, mixed> $validated */
    public function __invoke(User $admin, array $validated): AnnouncementDispatchResult
    {
        $submissionKey = (string) $validated['submission_key'];
        $existing = Announcement::query()->where('submission_key', $submissionKey)->first();

        if ($existing !== null) {
            return new AnnouncementDispatchResult($existing, true);
        }

        $targetType = AnnouncementTargetType::from((string) $validated['target_type']);
        $recipients = $this->resolveRecipients->query(
            $targetType,
            $validated['target_certification_id'] ?? null,
            $validated['target_user_id'] ?? null,
        );
        $recipientCount = (clone $recipients)->count();

        try {
            $announcement = DB::transaction(fn (): Announcement => Announcement::create([
                'created_by' => $admin->id,
                'target_type' => $targetType,
                'target_certification_id' => $validated['target_certification_id'] ?? null,
                'target_user_id' => $validated['target_user_id'] ?? null,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'dispatched_count' => $recipientCount,
                'dispatch_status' => AnnouncementDispatchStatus::Processing,
                'dispatched_at' => now(),
                'submission_key' => $submissionKey,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Announcement::query()->where('submission_key', $submissionKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return new AnnouncementDispatchResult($existing, true);
        }

        if ($recipientCount === 0) {
            $announcement->update(['dispatch_status' => AnnouncementDispatchStatus::Succeeded]);

            return new AnnouncementDispatchResult($announcement);
        }

        try {
            $recipients->chunkById(100, function ($users) use ($announcement): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $user->notify(new AnnouncementNotification($announcement));
                }
            });

            $announcement->update(['dispatch_status' => AnnouncementDispatchStatus::Succeeded]);
        } catch (Throwable $exception) {
            Log::error('Announcement dispatch failed.', [
                'announcement_id' => $announcement->id,
                'submission_key' => $announcement->submission_key,
                'exception' => $exception,
            ]);

            try {
                $announcement->update(['dispatch_status' => AnnouncementDispatchStatus::Failed]);
            } catch (Throwable $statusException) {
                Log::error('Announcement failed status update failed.', [
                    'announcement_id' => $announcement->id,
                    'exception' => $statusException,
                ]);
            }
        }

        return new AnnouncementDispatchResult($announcement);
    }
}
