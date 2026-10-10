<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\UseCases\MeetingReminder\SendReminderAction;
use App\UseCases\MeetingReminder\SendReminderResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendMeetingRemindersCommand extends Command
{
    private const TIMEZONE = 'Asia/Tokyo';

    /** @var array<string, int> */
    private const INITIAL_SUMMARY = [
        'acquired' => 0,
        'not_eligible' => 0,
        'duplicate' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    protected $signature = 'notifications:send-meeting-reminders {--window=}';

    protected $description = '予約済み面談のリマインダー通知を送信する。';

    /** @var array<string, int> */
    private array $summary = self::INITIAL_SUMMARY;

    public function handle(SendReminderAction $sendReminder): int
    {
        $this->summary = self::INITIAL_SUMMARY;

        $window = $this->option('window');
        if (! is_string($window) || ! in_array($window, ['eve', 'one_hour_before'], true)) {
            $this->error('The --window option must be eve or one_hour_before.');

            return self::FAILURE;
        }

        [$executionStart, $executionEnd] = $this->executionBounds($window);
        [$windowStart, $windowEnd] = $this->meetingBounds($window);
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($now->lessThan($executionStart) || $now->greaterThanOrEqualTo($executionEnd)) {
            $this->line('The current time is outside the reminder window.');

            return self::SUCCESS;
        }

        try {
            Meeting::query()
                ->where('status', MeetingStatus::Reserved->value)
                ->where('scheduled_at', '>=', $windowStart->format('Y-m-d H:i:s'))
                ->where('scheduled_at', '<', $windowEnd->format('Y-m-d H:i:s'))
                ->where('scheduled_at', '>', $now->format('Y-m-d H:i:s'))
                ->orderBy('id')
                ->chunkById(100, function ($meetings) use ($sendReminder, $window): void {
                    foreach ($meetings as $meeting) {
                        foreach ([$meeting->student_id, $meeting->coach_id] as $recipientId) {
                            $this->processRecipient($sendReminder, $meeting->id, $window, $recipientId);
                        }
                    }
                });
        } catch (Throwable $exception) {
            $this->summary['failed']++;
            Log::error('Meeting reminder command failed.', [
                'window' => $window,
                'stage' => 'extract',
                'status' => 'failed',
                'exception' => $exception::class,
            ]);
        }

        $this->info(sprintf(
            'Meeting reminders processed: acquired=%d, not_eligible=%d, duplicate=%d, skipped=%d, failed=%d.',
            $this->summary['acquired'],
            $this->summary['not_eligible'],
            $this->summary['duplicate'],
            $this->summary['skipped'],
            $this->summary['failed'],
        ));

        return $this->summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processRecipient(
        SendReminderAction $sendReminder,
        string $meetingId,
        string $window,
        string $recipientId,
    ): void {
        try {
            $result = $sendReminder($meetingId, $window, $recipientId);
            $this->collect($result);
        } catch (Throwable $exception) {
            $this->summary['failed']++;
            Log::error('Meeting reminder recipient failed.', [
                'meeting_id' => $meetingId,
                'window' => $window,
                'recipient_id' => $recipientId,
                'stage' => 'recipient',
                'status' => 'failed',
                'exception' => $exception::class,
            ]);
        }
    }

    private function collect(SendReminderResult $result): void
    {
        if ($result->acquisitionStatus === 'acquired') {
            $this->summary['acquired']++;
        } elseif (isset($this->summary[$result->acquisitionStatus])) {
            $this->summary[$result->acquisitionStatus]++;
        }

        foreach ([$result->databaseStatus, $result->mailStatus] as $status) {
            if (in_array($status, ['failed'], true)) {
                $this->summary['failed']++;
            } elseif ($status === 'skipped_after_start' || $status === 'skipped') {
                $this->summary['skipped']++;
            }
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function executionBounds(string $window): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($window === 'eve') {
            $start = $now->startOfDay()->setTime(18, 0);

            return [$start, $start->addMinute()];
        }

        $start = $now->startOfMinute();

        return [$start, $start->addMinute()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function meetingBounds(string $window): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($window === 'eve') {
            $start = $now->startOfDay()->addDay();

            return [$start, $start->addDay()];
        }

        $start = $now->startOfMinute()->addHour();

        return [$start, $start->addMinute()];
    }
}
