<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GoogleCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_primary_credential_for_a_coach(): void
    {
        $credential = GoogleCredential::factory()->create();

        $this->assertSame('primary', $credential->calendar_id);
        $this->assertSame('coach', $credential->coach->role->value);
        $this->assertStringStartsWith('dummy-', $credential->access_token);
        $this->assertStringStartsWith('dummy-', $credential->refresh_token);
        $this->assertInstanceOf(Carbon::class, $credential->expires_at);
        $this->assertInstanceOf(Carbon::class, $credential->connected_at);
    }

    public function test_user_has_one_google_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->for($coach, 'coach')->create();

        $this->assertTrue($coach->googleCredential->is($credential));
        $this->assertTrue($credential->coach->is($coach));
    }

    public function test_tokens_are_hidden_from_array_and_json_output(): void
    {
        $credential = GoogleCredential::factory()->create();

        $this->assertArrayNotHasKey('access_token', $credential->toArray());
        $this->assertArrayNotHasKey('refresh_token', $credential->toArray());
        $this->assertStringNotContainsString('dummy-access-token-', $credential->toJson());
        $this->assertStringNotContainsString('dummy-refresh-token-', $credential->toJson());
    }

    public function test_coach_id_is_unique(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->for($coach, 'coach')->create();

        try {
            GoogleCredential::factory()->for($coach, 'coach')->create();
            $this->fail('A coach must not have multiple Google credentials.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('google_credentials', $exception->getMessage());
        }
    }

    public function test_meeting_keeps_google_connection_history_when_credential_is_deleted(): void
    {
        $credential = GoogleCredential::factory()->create();
        $meeting = Meeting::factory()->forCoach($credential->coach)->create([
            'google_connection_id' => $credential->id,
            'google_event_id' => 'event-123',
            'google_event_created_at' => now(),
        ]);

        $credential->delete();
        $meeting->refresh();

        $this->assertSame($credential->id, $meeting->google_connection_id);
        $this->assertSame('event-123', $meeting->google_event_id);
        $this->assertNotNull($meeting->google_event_created_at);
    }
}
