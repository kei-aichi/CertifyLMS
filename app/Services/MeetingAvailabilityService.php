<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 担当コーチ集合の面談可能時間枠を 60 分単位で展開し、空きスロットを集計する Service。
 *
 * 受講生の予約画面が「該当資格の担当コーチ全員の有効枠 Union」を 1 日単位で取得し、
 * 既存予約済時刻 を除外して各スロットの「予約可能なコーチ数」を返す。受講生にコーチ個別は提示せず、
 * 予約確定時にコーチを自動割当する。
 */
final class MeetingAvailabilityService
{
    public function __construct(private readonly GoogleCalendarService $googleCalendar) {}

    /**
     * 指定 Certification の担当コーチ集合について、指定日 1 日分の 60 分単位空きスロットを返す。
     *
     * 1 リクエストあたり availability 1 クエリ + meetings 1 クエリ で完結させる。
     *
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function slotsForCertification(Certification $certification, Carbon $date): Collection
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayOfWeek = $date->dayOfWeek;

        $coaches = $certification->coaches()->get();
        if ($coaches->isEmpty()) {
            return collect();
        }

        $coachIds = $coaches->pluck('id')->all();

        $availabilities = CoachAvailability::query()
            ->whereIn('coach_id', $coachIds)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $existingMeetings = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
            ->get(['coach_id', 'scheduled_at']);

        // 予約済スロットを (coach_id => Set<H:i>) で索引化
        $bookedByCoach = $existingMeetings
            ->groupBy('coach_id')
            ->map(fn ($rows) => $rows->map(fn (Meeting $m) => $m->scheduled_at->format('H:i'))->all());

        /** @var array<string, int> $slotCounts スロット開始時刻(H:i) → available coach 数 */
        $slotCounts = [];
        $now = now();

        $googleBusyByCoach = [];
        foreach ($coaches as $coach) {
            $credential = $coach->googleCredential;
            if ($credential === null) {
                continue;
            }
            try {
                $googleBusyByCoach[$coach->id] = $this->googleCalendar->freeBusy($credential, $dayStart, $dayEnd);
            } catch (\Throwable $exception) {
                Log::warning('Google Calendar FreeBusy failed; using LMS availability.', [
                    'operation' => 'freebusy',
                    'coach_id' => $coach->id,
                    'connection_id' => $credential->getKey(),
                    'error_class' => $exception::class,
                ]);
            }
        }

        foreach ($availabilities as $availability) {
            $slot = Carbon::parse($date->format('Y-m-d').' '.$availability->start_time);
            $end = Carbon::parse($date->format('Y-m-d').' '.$availability->end_time);

            while ($slot->copy()->addHour() <= $end) {
                $slotKey = $slot->format('H:i');
                $coachId = $availability->coach_id;

                if ($slot->lessThanOrEqualTo($now)) {
                    $slot->addHour();
                    continue;
                }

                $booked = $bookedByCoach[$coachId] ?? [];

                $slotEnd = $slot->copy()->addHour();
                $busy = $googleBusyByCoach[$coachId] ?? [];
                $googleBooked = collect($busy)->contains(fn (array $period): bool => $slot->lt($period['end']) && $slotEnd->gt($period['start'])
                );

                if (! in_array($slotKey, $booked, true) && ! $googleBooked) {
                    $slotCounts[$slotKey] = ($slotCounts[$slotKey] ?? 0) + 1;
                }

                $slot->addHour();
            }
        }

        ksort($slotCounts);

        return collect($slotCounts)->map(function (int $count, string $time) use ($date) {
            $start = Carbon::parse($date->format('Y-m-d').' '.$time);

            return [
                'slot_start' => $start,
                'slot_end' => $start->copy()->addHour(),
                'available_coach_count' => $count,
            ];
        })->values();
    }

    /**
     * 指定 scheduled_at が certification 担当コーチ集合の有効枠内かを検証する。
     * 枠外なら MeetingOutOfAvailabilityException を throw する。
     *
     * @throws MeetingOutOfAvailabilityException
     */
    public function validateSlot(Certification $certification, Carbon $scheduledAt): void
    {
        $coachIds = $certification->coaches()->pluck('users.id');
        $time = $scheduledAt->format('H:i:s');

        // ここでは「対応可能時間枠の存在」だけを検証する。
        // 予約済みMeetingによる満席判定は、予約処理側で候補コーチ行を
        // ロックした後に再確認し、409 (Conflict) として扱う。
        $matched = CoachAvailability::query()
            ->whereIn('coach_id', $coachIds)
            ->where('day_of_week', $scheduledAt->dayOfWeek)
            ->where('is_active', true)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>', $time)
            ->exists();

        if (! $matched) {
            throw new MeetingOutOfAvailabilityException;
        }
    }
}
