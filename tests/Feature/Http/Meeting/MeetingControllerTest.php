<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    private function attachConsumedQuota(Meeting $meeting): void
    {
        $transaction = MeetingQuotaTransaction::factory()
            ->consumed()
            ->state([
                'user_id' => $meeting->student_id,
                'related_meeting_id' => $meeting->id,
            ])
            ->create();

        $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);
    }

    /**
     * @param Closure(Meeting, MeetingQuotaTransaction): void $mutator
     */
    private function assertCancellationRejected(Closure $mutator): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $transaction = MeetingQuotaTransaction::factory()
            ->consumed()
            ->state([
                'user_id' => $student->id,
                'related_meeting_id' => $meeting->id,
            ])
            ->create();
        $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

        $mutator($meeting, $transaction);
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Reserved, $fresh->status);
        $this->assertNull($fresh->canceled_at);
        $this->assertNull($fresh->canceled_by_user_id);
        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_student_index_lists_only_own_meetings(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $other = Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $response = $this->actingAs($student)->get(route('meetings.index'));

        $response->assertOk();
        $response->assertViewIs('meeting.index');
        $response->assertViewHas('meetings', fn ($meetings) => $meetings->contains('id', $own->id)
            && ! $meetings->contains('id', $other->id));
    }

    public function test_show_blocks_third_party(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thirdParty = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($thirdParty)->get(route('meetings.show', $meeting));

        $response->assertForbidden();
    }

    public function test_show_allows_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $this->actingAs($student)->get(route('meetings.show', $meeting))->assertOk();
        $this->actingAs($coach)->get(route('meetings.show', $meeting))->assertOk();
    }

    public function test_admin_can_view_any_meeting(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $this->actingAs($admin)
            ->get(route('meetings.show', $meeting))
            ->assertOk();
    }

    public function test_non_active_student_cannot_view_or_cancel_own_meeting(): void
    {
        foreach (['graduated', 'invited', 'withdrawn'] as $status) {
            $student = User::factory()->student()->create(['status' => $status]);
            $coach = User::factory()->coach()->create();
            $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
                'scheduled_at' => now()->addDays(3)->startOfHour(),
            ]);

            $this->actingAs($student)->get(route('meetings.show', $meeting))->assertForbidden();
            $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertForbidden();
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        }
    }

    public function test_create_requires_enrollment_ownership(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $foreignEnrollment = Enrollment::factory()->for($other, 'user')->for($certification)->learning()->create();

        $response = $this->actingAs($student)->get(route('meetings.create', $foreignEnrollment));

        $response->assertForbidden();
    }

    public function test_store_creates_meeting_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved->value,
            'meeting_url_snapshot' => 'https://meet.example.com/coach-room',
        ]);
    }

    public function test_store_rejects_non_zero_minutes(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 30); // 次の月曜 10:30(分が 0 でない=不正)
        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertSessionHasErrors('scheduled_at');
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_cancel_blocks_third_party(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thirdParty = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($thirdParty)->post(route('meetings.cancel', $meeting));

        $response->assertForbidden();
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
    }

    public function test_cancel_allows_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $this->attachConsumedQuota($meeting);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
    }

    public function test_index_as_coach_only_lists_own_meetings(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $other = Meeting::factory()->reserved()->forCoach($otherCoach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $response = $this->actingAs($coach)->get(route('coach.meetings.index'));

        $response->assertOk();
        $response->assertViewIs('meeting.coach.index');
        $response->assertViewHas('meetings', fn ($meetings) => $meetings->contains('id', $own->id)
            && ! $meetings->contains('id', $other->id));
    }

    public function test_upsert_memo_only_for_assigned_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($otherCoach)->put(route('coach.meetings.memo', $meeting), [
            'body' => '他人のメモを書く試み',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('meeting_memos', ['meeting_id' => $meeting->id]);
    }

    public function test_upsert_memo_succeeds_for_assigned_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $response = $this->actingAs($coach)->put(route('coach.meetings.memo', $meeting), [
            'body' => '初回面談メモ',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '初回面談メモ',
        ]);
    }

    public function test_fetch_availability_returns_json_slots(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $date = now()->startOfDay()->next(Carbon::MONDAY)->format('Y-m-d'); // 次の月曜(未来)
        $response = $this->actingAs($student)->getJson(
            route('meetings.availability', $enrollment)."?date={$date}"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'date',
            'slots' => [
                '*' => ['slot_start', 'slot_end', 'available_coach_count'],
            ],
        ]);
        $this->assertCount(3, $response->json('slots'));
    }

    public function test_graduated_student_cannot_access_create(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $response = $this->actingAs($student)->get(route('meetings.create', $enrollment));

        $response->assertForbidden();
    }

    public function test_graduated_student_cannot_fetch_availability(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $date = now()->startOfDay()->next(Carbon::MONDAY)->format('Y-m-d');
        $this->actingAs($student)
            ->getJson(route('meetings.availability', $enrollment)."?date={$date}")
            ->assertForbidden();
    }

    public function test_graduated_student_cannot_store_meeting(): void
    {
        $student = User::factory()->student()->graduated()->create(['max_meetings' => 3]);
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $this->actingAs($student)
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i:s'),
                'topic' => 'blocked',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_store_blocks_double_booking_for_same_coach_and_slot(): void
    {
        // Arrange: 予約可能コンテキスト + 同コーチ・同時刻に canceled 面談を 1 件先在させる。
        // canceled は候補抽出(予約済コーチ除外)をすり抜けるが、(coach_id, scheduled_at) UNIQUE は
        // status を問わず効くため、並行を起こさず決定論的に二重予約の衝突を再現できる。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0); // 次の月曜 10:00(未来)

        Meeting::factory()->canceled()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => $scheduledAt,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->from(route('meetings.create', $enrollment))
            ->post(route('meetings.store', $enrollment), [
                'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                'topic' => '相談したい',
            ]);

        // Assert: 二重予約は成立せず、新規 reserved は作られない(canceled の 1 件のみが残る)
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(
            0,
            Meeting::query()->where('status', MeetingStatus::Reserved->value)->count(),
            '同コーチ・同時刻の二重予約は (coach_id, scheduled_at) UNIQUE で阻止されるはず',
        );
    }

    public function test_store_allows_reservation_with_null_coach_meeting_url_and_snapshots_null(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => null]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i:s'),
            'topic' => 'URLなし予約',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'meeting_url_snapshot' => null,
        ]);
    }

    public function test_cancel_refunds_meeting_quota(): void
    {
        // Arrange: 予約済(残数消費済)面談 1 件。キャンセルで返却記録が作られることを確認する。
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $this->attachConsumedQuota($meeting);

        // Act
        $before = app(MeetingQuotaService::class)->remaining($student);
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Assert: キャンセル成立 + 消費分 1 回が返却記録として作られる
        $response->assertRedirect();
        $response->assertSessionHas(
            'success',
            '面談をキャンセルしました。面談回数を返却しました。'
        );
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame($before + 1, app(MeetingQuotaService::class)->remaining($student));
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
    }

    public function test_cancel_rejects_when_quota_transaction_id_is_null(): void
    {
        $this->assertCancellationRejected(
            fn (Meeting $meeting) => $meeting->update(['meeting_quota_transaction_id' => null]),
        );
    }

    public function test_cancel_rejects_when_quota_transaction_does_not_exist(): void
    {
        $this->assertCancellationRejected(function (Meeting $meeting): void {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $meeting->update(['meeting_quota_transaction_id' => (string) Str::ulid()]);
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    public function test_cancel_rejects_when_quota_transaction_user_does_not_match_student(): void
    {
        $this->assertCancellationRejected(function (Meeting $meeting, MeetingQuotaTransaction $transaction): void {
            $otherStudent = User::factory()->student()->create();
            $transaction->update(['user_id' => $otherStudent->id]);
        });
    }

    public function test_cancel_rejects_when_quota_transaction_meeting_does_not_match(): void
    {
        $this->assertCancellationRejected(function (Meeting $meeting, MeetingQuotaTransaction $transaction): void {
            $otherMeeting = Meeting::factory()->reserved()->create();
            $transaction->update(['related_meeting_id' => $otherMeeting->id]);
        });
    }

    public function test_cancel_rejects_when_quota_transaction_type_is_not_consumed(): void
    {
        $this->assertCancellationRejected(
            fn (Meeting $meeting, MeetingQuotaTransaction $transaction) => DB::table('meeting_quota_transactions')
                ->where('id', $transaction->id)
                ->update(['type' => MeetingQuotaTransactionType::Purchased->value, 'amount' => 1]),
        );
    }

    public function test_cancel_rejects_when_quota_transaction_amount_is_not_minus_one(): void
    {
        $this->assertCancellationRejected(
            fn (Meeting $meeting, MeetingQuotaTransaction $transaction) => $transaction->update(['amount' => -2]),
        );
    }

    public function test_cancel_rolls_back_when_refund_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $this->attachConsumedQuota($meeting);

        $event = 'eloquent.creating: '.MeetingQuotaTransaction::class;
        $listenerCalled = false;
        Event::listen($event, static function (MeetingQuotaTransaction $transaction) use (&$listenerCalled): void {
            if ($transaction->type === MeetingQuotaTransactionType::Refunded) {
                $listenerCalled = true;
                throw new \RuntimeException('refund insert failure');
            }
        });

        $this->withoutExceptionHandling();
        $exception = null;
        try {
            $this->actingAs($student)->post(route('meetings.cancel', $meeting));
        } catch (\Throwable $caught) {
            $exception = $caught;
        } finally {
            Event::forget($event);
        }

        $this->assertTrue($listenerCalled);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('refund insert failure', $exception?->getMessage());

        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Reserved, $fresh->status);
        $this->assertNull($fresh->canceled_at);
        $this->assertNull($fresh->canceled_by_user_id);
        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_second_cancel_does_not_refund_again(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $this->attachConsumedQuota($meeting);
        $before = app(MeetingQuotaService::class)->remaining($student);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();
        $second = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $second->assertForbidden();
        $this->assertSame($before + 1, app(MeetingQuotaService::class)->remaining($student));
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_cancel_rejects_at_meeting_start_time(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now(),
        ]);
        $this->attachConsumedQuota($meeting);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
        ]);
    }

    public function test_cancel_rejects_after_meeting_start_time(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subSecond(),
        ]);
        $this->attachConsumedQuota($meeting);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
        ]);
    }
}
