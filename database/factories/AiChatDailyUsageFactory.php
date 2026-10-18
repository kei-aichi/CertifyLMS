<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatDailyUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiChatDailyUsage> */
class AiChatDailyUsageFactory extends Factory
{
    protected $model = AiChatDailyUsage::class;

    public function definition(): array
    {
        return ['user_id' => User::factory()->student()->inProgress(), 'usage_date' => now()->toDateString(), 'send_count' => 0];
    }
}
