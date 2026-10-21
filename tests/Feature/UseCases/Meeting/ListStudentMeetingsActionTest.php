<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ListStudentMeetingsAction;
use App\UseCases\Meeting\ViewModels\StudentMeetingsIndexViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListStudentMeetingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_own_upcoming_meetings_and_remaining_quota(): void
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $own = Meeting::factory()->forStudent($student)->inFuture()->create();
        Meeting::factory()->inFuture()->create();

        $result = app(ListStudentMeetingsAction::class)($student);

        $this->assertInstanceOf(StudentMeetingsIndexViewModel::class, $result);
        $this->assertSame('upcoming', $result->filter);
        $this->assertSame(3, $result->meetingsRemaining);
        $this->assertSame([$own->id], $result->meetings->getCollection()->modelKeys());
    }

    public function test_supports_past_and_all_filters_with_descending_order(): void
    {
        $student = User::factory()->student()->create();
        $past = Meeting::factory()->forStudent($student)->completed()->create(['scheduled_at' => now()->subDays(2)]);
        $future = Meeting::factory()->forStudent($student)->inFuture()->create(['scheduled_at' => now()->addDays(2)]);

        $action = app(ListStudentMeetingsAction::class);
        $this->assertSame([$past->id], $action($student, 'past')->meetings->getCollection()->modelKeys());
        $this->assertSame([$future->id, $past->id], $action($student, 'all')->meetings->getCollection()->modelKeys());
    }

    public function test_paginates_after_twenty_records(): void
    {
        $student = User::factory()->student()->create();
        Meeting::factory()->count(21)->forStudent($student)->inFuture()->create();

        $result = app(ListStudentMeetingsAction::class)($student);

        $this->assertSame(20, $result->meetings->perPage());
        $this->assertSame(21, $result->meetings->total());
    }
}
