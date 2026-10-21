<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Tests\TestCase;

final class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_cancel_and_refund_quota(): void
    {
        Notification::fake();
        [$meeting, $student, $coach, $consumed] = $this->cancelableMeeting();
        $before = app(MeetingQuotaService::class)->remaining($student);

        $canceled = app(CancelAction::class)($meeting, $student);

        $this->assertSame(MeetingStatus::Canceled, $canceled->status);
        $this->assertSame($student->id, $canceled->canceled_by_user_id);
        $this->assertNotNull($canceled->canceled_at);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
        ]);
        $this->assertSame($before + 1, app(MeetingQuotaService::class)->remaining($student));
        $this->assertSame($meeting->id, $consumed->related_meeting_id);
        Notification::assertSentTo($coach, MeetingCanceledNotification::class);
    }

    public function test_assigned_coach_can_cancel_and_notifies_student(): void
    {
        Notification::fake();
        [$meeting, , $coach, ] = $this->cancelableMeeting();

        $canceled = app(CancelAction::class)($meeting, $coach);

        $this->assertSame(MeetingStatus::Canceled, $canceled->status);
        $this->assertSame($coach->id, $canceled->canceled_by_user_id);
        Notification::assertSentTo($meeting->student, MeetingCanceledNotification::class);
    }

    public function test_rejects_canceled_meeting_without_refunding_again(): void
    {
        [$meeting, $student] = $this->cancelableMeeting();
        app(CancelAction::class)($meeting, $student);

        try {
            app(CancelAction::class)($meeting->fresh(), $student);
            $this->fail('Expected MeetingStatusTransitionException was not thrown.');
        } catch (MeetingStatusTransitionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseCount('meetings', 1);
    }

    public function test_rejects_actor_who_is_not_the_student_or_assigned_coach(): void
    {
        [$meeting, , ] = $this->cancelableMeeting();
        $otherUser = User::factory()->student()->inProgress()->create();

        $this->expectException(AuthorizationException::class);

        try {
            app(CancelAction::class)($meeting, $otherUser);
        } finally {
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
            $this->assertDatabaseCount('meeting_quota_transactions', 1);
        }
    }

    public function test_rejects_meeting_that_has_started_without_refund(): void
    {
        [$meeting, $student] = $this->cancelableMeeting(['scheduled_at' => now()->subSecond()]);

        $this->expectException(MeetingAlreadyStartedException::class);

        try {
            app(CancelAction::class)($meeting, $student);
        } finally {
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
            $this->assertDatabaseCount('meeting_quota_transactions', 1);
        }
    }

    public function test_rolls_back_meeting_update_when_refund_fails(): void
    {
        [$meeting, $student] = $this->cancelableMeeting();
        $event = 'eloquent.creating: '.MeetingQuotaTransaction::class;
        Event::listen($event, static function (MeetingQuotaTransaction $transaction): void {
            if ($transaction->type === MeetingQuotaTransactionType::Refunded) {
                throw new RuntimeException('refund insert failure');
            }
        });

        try {
            app(CancelAction::class)($meeting, $student);
            $this->fail('Expected refund failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('refund insert failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Reserved, $fresh->status);
        $this->assertNull($fresh->canceled_at);
        $this->assertNull($fresh->canceled_by_user_id);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    /** @param array<string, mixed> $overrides */
    private function cancelableMeeting(array $overrides = []): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create(array_merge([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ], $overrides));
        $consumed = MeetingQuotaTransaction::factory()->consumed()->create([
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
        ]);
        $meeting->update(['meeting_quota_transaction_id' => $consumed->id]);

        return [$meeting->fresh(), $student, $coach, $consumed];
    }
}
