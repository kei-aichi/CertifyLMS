<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->admin(),
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'dispatched_count' => 0,
            'dispatch_status' => AnnouncementDispatchStatus::Processing->value,
            'dispatched_at' => now(),
            'submission_key' => (string) Str::uuid(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'dispatch_status' => AnnouncementDispatchStatus::Succeeded->value,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'dispatch_status' => AnnouncementDispatchStatus::Failed->value,
        ]);
    }
}
