<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoogleCredential> */
class GoogleCredentialFactory extends Factory
{
    protected $model = GoogleCredential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coach_id' => User::factory()->coach(),
            'calendar_id' => 'primary',
            'access_token' => 'dummy-access-token-'.$this->faker->unique()->uuid(),
            'refresh_token' => 'dummy-refresh-token-'.$this->faker->unique()->uuid(),
            'expires_at' => now()->addHour(),
            'connected_at' => now(),
        ];
    }
}
