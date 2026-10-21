<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\MeetingQuota\MeetingQuotaTransactionMismatchException;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\ListCoachMeetingsAction;
use App\UseCases\Meeting\ListStudentMeetingsAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。予約 / キャンセル / メモ保存の
 * 状態変更系は残面談回数の消費・返却、通知発火、トランザクション境界を method 内で扱い、
 * Controller はリクエスト受付、認可、Action 呼び出し、レスポンス整形を担当する。
 * 認可は $this->authorize() または FormRequest::authorize()。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。filter (upcoming/past/all) クエリで履歴を切り替える。
     */
    public function index(IndexRequest $request, ListStudentMeetingsAction $action): View
    {
        $filter = $request->validated('filter') ?? 'upcoming';

        $result = $action($request->user(), $filter);

        return view('meeting.index', [
            'meetings' => $result->meetings,
            'filter' => $result->filter,
            'meetingsRemaining' => $result->meetingsRemaining,
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, ListCoachMeetingsAction $action): View
    {
        $filters = $request->validated();
        $result = $action($request->user(), $filters);

        return view('meeting.coach.index', [
            'meetings' => $result['meetings'],
            'filter' => $result['filter'],
            'studentFilter' => $result['studentFilter'],
            'enrollmentFilter' => $result['enrollmentFilter'],
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting, ShowAction $action): View
    {
        $this->authorize('view', $meeting);

        $meeting = $action($meeting);

        return view('meeting.show', [
            'meeting' => $meeting,
        ]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(): View
    {
        $user = auth()->user();
        $enrollments = $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get();

        return view('meeting.empty-state', [
            'enrollments' => $enrollments ?? collect(),
        ]);
    }

    /**
     * 受講生の予約申請。残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
     * 同時刻の競合は候補コーチUser行をロックし、ロック後の空き状況再確認で409へ変換する。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        MeetingAvailabilityService $availabilityService,
        CoachMeetingLoadService $coachLoadService,
        MeetingQuotaService $quotaService,
        ConsumeQuotaAction $consumeAction,
        GoogleCalendarService $googleCalendar,
    ): RedirectResponse {
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
        $topic = $request->validated('topic');
        $student = $enrollment->user;
        $initialCandidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);
        $googleBusyCoachIds = $this->googleBusyCoachIds($initialCandidates, $scheduledAt, $googleCalendar);

        $meeting = DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
            $availabilityService,
            $coachLoadService,
            $quotaService,
            $consumeAction,
            $googleBusyCoachIds,
        ) {
            if ($quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $availabilityService->validateSlot($enrollment->certification, $scheduledAt);

            $candidateIds = $this->findAvailableCoaches($enrollment->certification, $scheduledAt)
                ->pluck('id')
                ->sort()
                ->reject(fn (string $coachId): bool => $googleBusyCoachIds->contains($coachId))
                ->values();

            if ($candidateIds->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            // 全リクエストでコーチID昇順にUser行をロックし、候補判定を直列化する。
            // ロック取得後のMeeting確認は lockForUpdate() による current read とする。
            $lockedCandidates = $candidateIds->map(function (string $coachId): User {
                return User::query()
                    ->whereKey($coachId)
                    ->lockForUpdate()
                    ->firstOrFail();
            });

            $availableCandidates = $lockedCandidates->filter(function (User $coach) use ($scheduledAt): bool {
                return ! Meeting::query()
                    ->where('coach_id', $coach->id)
                    ->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
                    ->lockForUpdate()
                    ->exists();
            })->values();

            if ($availableCandidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $coachLoadService->leastLoadedCoach($availableCandidates);

            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'coach_id' => $coach->id,
                'student_id' => $student->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved->value,
                'topic' => $topic,
                'meeting_url_snapshot' => $coach->meeting_url,
                'google_connection_id' => $coach->googleCredential()->value('id'),
            ]);

            $transaction = ($consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            DB::afterCommit(function () use ($meeting): void {
                $meeting->loadMissing('coach');
                $coach = $meeting->coach;

                if ($coach !== null && $coach->status === UserStatus::InProgress) {
                    $coach->notify(new MeetingReservedNotification($meeting));
                }
            });

            return $meeting->fresh();
        });

        $this->createGoogleEventAfterCommit($meeting, $googleCalendar);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     */
    public function cancel(
        Meeting $meeting,
        RefundQuotaAction $refundAction,
        GoogleCalendarService $googleCalendar,
    ): RedirectResponse {
        $this->authorize('cancel', $meeting);

        $actor = auth()->user();
        $coachId = $meeting->coach_id;

        $canceledMeeting = DB::transaction(function () use ($meeting, $actor, $refundAction, $coachId) {
            // 予約処理と同じ順序で、コーチUser行を先にロックする。
            User::query()
                ->whereKey($coachId)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if (
                $locked === null
                || $locked->coach_id !== $coachId
                || $locked->status !== MeetingStatus::Reserved
            ) {
                throw MeetingStatusTransitionException::forCancel();
            }

            // Route Model Binding直後の認可結果だけに依存せず、ロック後の最新状態で再確認する。
            $this->authorize('cancel', $locked);

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $transaction = $locked->meeting_quota_transaction_id === null
                ? null
                : MeetingQuotaTransaction::query()
                    ->whereKey($locked->meeting_quota_transaction_id)
                    ->first();

            if (
                $transaction === null
                || $transaction->user_id !== $locked->student_id
                || $transaction->related_meeting_id !== $locked->id
                || $transaction->type !== MeetingQuotaTransactionType::Consumed
                || $transaction->amount !== -1
            ) {
                throw new MeetingQuotaTransactionMismatchException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            $refundAction($locked->student, $locked->id);

            DB::afterCommit(function () use ($locked, $actor): void {
                $locked->loadMissing(['coach', 'student', 'canceledBy']);
                $recipient = match ($actor->id) {
                    $locked->student_id => $locked->coach,
                    $locked->coach_id => $locked->student,
                    default => null,
                };

                if ($recipient !== null && $recipient->status === UserStatus::InProgress) {
                    $recipient->notify(new MeetingCanceledNotification($locked));
                }
            });

            return $locked->fresh();
        });

        $this->deleteGoogleEventAfterCommit($canceledMeeting, $googleCalendar);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request): RedirectResponse
    {
        $body = $request->validated('body');

        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, FetchAvailabilityAction $action): JsonResponse
    {
        $result = $action($enrollment, $request->validated('date'));
        $date = $result['date'];
        $slots = $result['slots'];

        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻に有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->with('googleCredential')
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }

    private function createGoogleEventAfterCommit(Meeting $meeting, GoogleCalendarService $googleCalendar): void
    {
        if ($meeting->google_connection_id === null) {
            return;
        }

        $credential = $meeting->coach?->googleCredential;
        if ($credential === null || $credential->getKey() !== $meeting->google_connection_id) {
            return;
        }

        try {
            $eventId = $googleCalendar->createEvent($credential, $meeting);
            $updated = Meeting::query()
                ->whereKey($meeting->getKey())
                ->where('status', MeetingStatus::Reserved->value)
                ->where('google_connection_id', $credential->getKey())
                ->whereNull('google_event_id')
                ->update(['google_event_id' => $eventId, 'google_event_created_at' => now()]);

            if ($updated === 0) {
                Log::warning('Google event created but Meeting was not updated.', [
                    'operation' => 'event_create',
                    'meeting_id' => $meeting->getKey(),
                    'connection_id' => $credential->getKey(),
                    'error_class' => 'meeting_state_changed',
                ]);
                $this->compensateOrphanedEvent($meeting, $credential, $eventId, $googleCalendar);
            }
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar event creation failed.', [
                'operation' => 'event_create',
                'meeting_id' => $meeting->getKey(),
                'connection_id' => $credential->getKey(),
                'error_class' => $exception::class,
            ]);
            $this->compensateOrphanedEvent($meeting, $credential, isset($eventId) ? $eventId : null, $googleCalendar);
        }
    }

    private function googleBusyCoachIds(Collection $candidates, Carbon $scheduledAt, GoogleCalendarService $googleCalendar): Collection
    {
        $from = $scheduledAt->copy()->startOfHour();
        $to = $from->copy()->addHour();
        $blocked = collect();

        foreach ($candidates as $coach) {
            $credential = $coach->googleCredential()->first();
            if ($credential === null) {
                continue;
            }

            try {
                $busy = $googleCalendar->freeBusy($credential, $from, $to);
                if (collect($busy)->contains(fn (array $period): bool => $scheduledAt->lt($period['end']) && $scheduledAt->copy()->addHour()->gt($period['start'])
                )) {
                    $blocked->push((string) $coach->getKey());
                }
            } catch (\Throwable $exception) {
                Log::warning('Google Calendar FreeBusy failed during reservation; using LMS availability.', [
                    'operation' => 'freebusy_reservation',
                    'coach_id' => $coach->getKey(),
                    'connection_id' => $credential->getKey(),
                    'error_class' => $exception::class,
                ]);
            }
        }

        return $blocked;
    }

    private function compensateOrphanedEvent(Meeting $meeting, $credential, ?string $eventId, GoogleCalendarService $googleCalendar): void
    {
        if ($eventId === null) {
            return;
        }

        $latest = Meeting::query()->find($meeting->getKey());
        if ($latest?->status !== MeetingStatus::Canceled
            || $latest->google_connection_id !== $credential->getKey()) {
            return;
        }

        $currentCredential = $latest->coach?->googleCredential;
        if ($currentCredential === null || $currentCredential->getKey() !== $credential->getKey()) {
            return;
        }

        try {
            $googleCalendar->deleteEvent($currentCredential, $credential->getKey(), $eventId);
        } catch (\Throwable $exception) {
            Log::warning('Google orphan event compensation failed.', [
                'operation' => 'event_compensation_delete',
                'meeting_id' => $latest->getKey(),
                'connection_id' => $credential->getKey(),
                'error_class' => $exception::class,
            ]);
        }
    }

    private function deleteGoogleEventAfterCommit(Meeting $meeting, GoogleCalendarService $googleCalendar): void
    {
        if ($meeting->google_event_id === null || $meeting->google_connection_id === null) {
            return;
        }

        $credential = $meeting->coach?->googleCredential;
        if ($credential === null) {
            return;
        }

        $apiConnectionId = (string) $meeting->google_connection_id;
        $eventId = (string) $meeting->google_event_id;

        try {
            $googleCalendar->deleteEvent($credential, $apiConnectionId, $eventId);

            $latest = Meeting::query()
                ->with('coach.googleCredential')
                ->find($meeting->getKey());
            $currentConnectionId = $latest?->coach?->googleCredential?->getKey();

            if ($latest === null || $currentConnectionId !== $apiConnectionId) {
                Log::warning('Google event deletion completed after connection state changed.', [
                    'operation' => 'event_delete_state_changed',
                    'meeting_id' => $meeting->getKey(),
                    'connection_id' => $apiConnectionId,
                    'error_class' => 'connection_state_changed',
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar event deletion failed.', [
                'operation' => 'event_delete',
                'meeting_id' => $meeting->getKey(),
                'connection_id' => $meeting->google_connection_id,
                'error_class' => $exception::class,
            ]);
        }
    }
}
