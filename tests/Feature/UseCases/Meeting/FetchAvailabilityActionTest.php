<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_slots_for_enrollment_certification_and_requested_date(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->monday()->timeRange('09:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($certification)->create();
        $service = new MeetingAvailabilityService(Mockery::mock(GoogleCalendarService::class));

        $date = Carbon::parse('2026-06-01T12:34:00', 'Asia/Tokyo');
        $originalDate = $date->copy();
        $slots = (new FetchAvailabilityAction($service))($enrollment, $date);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $slots);
        $this->assertCount(2, $slots);
        $this->assertSame(['09:00', '10:00'], $slots->pluck('slot_start')->map->format('H:i')->all());
        $this->assertSame([1, 1], $slots->pluck('available_coach_count')->all());
        $this->assertSame($originalDate->toIso8601String(), $date->toIso8601String());
    }

    public function test_excludes_other_certification_and_other_date_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        $this->attachCoach($otherCertification, $otherCoach);
        CoachAvailability::factory()->forCoach($coach)->monday()->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($otherCoach)->monday()->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($coach)->tuesday()->timeRange('09:00:00', '10:00:00')->create();
        $enrollment = Enrollment::factory()->for($certification)->create();
        $service = new MeetingAvailabilityService(Mockery::mock(GoogleCalendarService::class));

        $slots = (new FetchAvailabilityAction($service))($enrollment, Carbon::parse('2026-06-01', 'Asia/Tokyo'));

        $this->assertSame(['09:00'], $slots->pluck('slot_start')->map->format('H:i')->all());
        $this->assertSame([1], $slots->pluck('available_coach_count')->all());
    }

    public function test_returns_empty_slots_without_extra_processing(): void
    {
        $enrollment = Enrollment::factory()->create();
        $service = new MeetingAvailabilityService(Mockery::mock(GoogleCalendarService::class));

        $slots = (new FetchAvailabilityAction($service))($enrollment, Carbon::parse('2026-06-01', 'Asia/Tokyo'));

        $this->assertTrue($slots->isEmpty());
    }

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
