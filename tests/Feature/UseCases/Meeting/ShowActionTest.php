<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_meeting_and_required_related_data(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $canceledBy = User::factory()->student()->create();
        $meeting = Meeting::factory()->forStudent($student)->forCoach($coach)->canceled()->create([
            'canceled_by_user_id' => $canceledBy->id,
        ]);
        MeetingMemo::factory()->forMeeting($meeting)->create();

        $result = app(ShowAction::class)($meeting);

        $this->assertSame($meeting->id, $result->id);
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
        $this->assertSame($canceledBy->id, $result->canceledBy->id);
        $this->assertNotNull($result->meetingMemo);
    }
}
