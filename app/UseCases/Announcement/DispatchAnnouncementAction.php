<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use App\Jobs\Announcement\DispatchAnnouncementRecipientsJob;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
        try {
            [$announcement, $recipientCount] = DB::transaction(function () use (
                $admin,
                $targetType,
                $validated,
                $submissionKey,
                $recipients,
            ): array {
                $announcement = Announcement::create([
                    'created_by' => $admin->id,
                    'target_type' => $targetType,
                    'target_certification_id' => $validated['target_certification_id'] ?? null,
                    'target_user_id' => $validated['target_user_id'] ?? null,
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                    'dispatched_count' => 0,
                    'dispatch_status' => AnnouncementDispatchStatus::Processing,
                    'dispatched_at' => now(),
                    'submission_key' => $submissionKey,
                ]);

                $recipientCount = 0;
                $recipients->chunkById(100, function ($users) use ($announcement, $validated, &$recipientCount): void {
                    foreach ($users as $user) {
                        /** @var User $user */
                        AnnouncementRecipient::create([
                            'announcement_id' => $announcement->id,
                            'recipient_id' => $user->id,
                            'title_snapshot' => $validated['title'],
                            'body_snapshot' => $validated['body'],
                        ]);
                        $recipientCount++;
                    }
                });

                $announcement->update([
                    'dispatched_count' => $recipientCount,
                    'dispatch_status' => $recipientCount === 0
                        ? AnnouncementDispatchStatus::Succeeded
                        : AnnouncementDispatchStatus::Processing,
                ]);

                return [$announcement, $recipientCount];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Announcement::query()->where('submission_key', $submissionKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return new AnnouncementDispatchResult($existing, true);
        }

        if ($recipientCount > 0) {
            DB::afterCommit(static function () use ($announcement): void {
                DispatchAnnouncementRecipientsJob::dispatch($announcement->id);
            });
        }

        return new AnnouncementDispatchResult($announcement);
    }
}
