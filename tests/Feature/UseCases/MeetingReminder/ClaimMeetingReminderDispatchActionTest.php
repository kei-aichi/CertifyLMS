<?php
declare(strict_types=1);
namespace Tests\Feature\UseCases\MeetingReminder;
use App\Models\MeetingReminderDispatch;
use App\UseCases\MeetingReminder\AcquireQueueDispatchAction;
use App\UseCases\MeetingReminder\ClaimMeetingReminderDispatchAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ClaimMeetingReminderDispatchActionTest extends TestCase
{
    use RefreshDatabase;
    public function test_pending_channel_is_claimed_without_queue_timestamp(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30','Asia/Tokyo'); [$m,$s]=$this->createMeeting('2026-10-11 10:00:00');
        $r=(new AcquireQueueDispatchAction)($m->id,'eve',$s->id); $claim=(new ClaimMeetingReminderDispatchAction)($r->dispatch->id,'database');
        $this->assertNotNull($claim); $this->assertSame('processing',$claim->dispatch->database_status); $this->assertNotNull($claim->token); $this->assertNull($claim->dispatch->database_queued_at); $this->assertNotNull($claim->dispatch->database_processing_started_at);
    }
    public function test_claiming_same_channel_twice_is_blocked_and_token_is_reused_only_after_reset(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30','Asia/Tokyo'); [$m,$s]=$this->createMeeting('2026-10-11 10:00:00'); $r=(new AcquireQueueDispatchAction)($m->id,'eve',$s->id);
        $action=new ClaimMeetingReminderDispatchAction; $first=$action($r->dispatch->id,'mail'); $second=$action($r->dispatch->id,'mail');
        $this->assertNotNull($first); $this->assertNull($second); $this->assertSame($first->token,$r->dispatch->fresh()->mail_dispatch_token);
    }
    public function test_legacy_and_succeeded_channels_are_not_claimed(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:00:30','Asia/Tokyo'); [$m,$s]=$this->createMeeting('2026-10-11 10:00:00');
        $legacy=MeetingReminderDispatch::create(['meeting_id'=>$m->id,'window'=>'eve','recipient_id'=>$s->id]); $this->assertNull((new ClaimMeetingReminderDispatchAction)($legacy->id,'database'));
        $queue=(new AcquireQueueDispatchAction)($m->id,'eve',$s->id)->dispatch;
        $queue->update(['database_status'=>'succeeded']);
        $this->assertNull((new ClaimMeetingReminderDispatchAction)($queue->id,'database'));
    }
    private function createMeeting(string $scheduled): array
    {
        $student=\App\Models\User::factory()->student()->create(); $coach=\App\Models\User::factory()->coach()->create(); $meeting=\App\Models\Meeting::factory()->create(['student_id'=>$student->id,'coach_id'=>$coach->id,'scheduled_at'=>$scheduled,'status'=>\App\Enums\MeetingStatus::Reserved]); return [$meeting,$student,$coach];
    }
}
