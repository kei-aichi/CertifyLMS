<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PaymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_only_expected_states_and_is_idempotent(): void
    {
        MeetingPack::factory()->published()->create(['price' => 500, 'meeting_count' => 3]);
        User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        User::factory()->student()->create(['email' => 'student-noquota@certify-lms.test']);
        User::factory()->student()->create(['email' => 'demo@example.test']);

        $this->seed(PaymentSeeder::class);
        $this->seed(PaymentSeeder::class);

        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertSame(1, Payment::query()->where('status', 'succeeded')->count());
        $this->assertSame(1, Payment::query()->where('status', 'pending')->count());
        $this->assertSame(1, Payment::query()->where('status', 'failed')->count());
        $this->assertDatabaseHas('meeting_quota_transactions', ['type' => 'purchased', 'amount' => 3]);
    }
}
