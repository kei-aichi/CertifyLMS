<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_start_oauth_and_state_is_stored(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleOAuthService::class, function ($mock): void {
            $mock->shouldReceive('authorizationUrl')->once()->andReturn('https://accounts.google.test/oauth');
        });

        $response = $this->actingAs($coach)->get(route('settings.google-calendar.redirect'));

        $response->assertRedirect('https://accounts.google.test/oauth');
        $this->assertSame((string) $coach->id, session('google_calendar.oauth.coach_id'));
        $this->assertNotEmpty(session('google_calendar.oauth.state'));
    }

    public function test_callback_rejects_invalid_state_without_saving_credentials(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleOAuthService::class, function ($mock): void {
            $mock->shouldNotReceive('exchangeCode');
        });

        $response = $this->actingAs($coach)
            ->withSession(['google_calendar.oauth' => ['state' => 'expected', 'coach_id' => $coach->id]])
            ->get(route('settings.google-calendar.callback', ['state' => 'invalid', 'code' => 'code']));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_callback_saves_complete_credentials_and_replaces_old_connection(): void
    {
        $coach = User::factory()->coach()->create();
        $old = GoogleCredential::factory()->for($coach, 'coach')->create();
        $this->mock(GoogleOAuthService::class, function ($mock): void {
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'dummy-access',
                'refresh_token' => 'dummy-refresh',
                'expires_in' => 3600,
            ]);
        });

        $response = $this->actingAs($coach)
            ->withSession(['google_calendar.oauth' => ['state' => 'expected', 'coach_id' => $coach->id]])
            ->get(route('settings.google-calendar.callback', ['state' => 'expected', 'code' => 'code']));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('google_credentials', ['id' => $old->id]);
        $this->assertDatabaseHas('google_credentials', ['coach_id' => $coach->id, 'calendar_id' => 'primary']);
    }

    public function test_callback_does_not_save_incomplete_token(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleOAuthService::class, function ($mock): void {
            $mock->shouldReceive('exchangeCode')->once()->andReturn(['access_token' => 'only-access']);
        });

        $this->actingAs($coach)
            ->withSession(['google_calendar.oauth' => ['state' => 'expected', 'coach_id' => $coach->id]])
            ->get(route('settings.google-calendar.callback', ['state' => 'expected', 'code' => 'code']))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_coach_can_disconnect_without_calling_google(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->for($coach, 'coach')->create();
        $this->mock(GoogleOAuthService::class, function ($mock): void {
            $mock->shouldNotReceive('*');
        });

        $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_non_coach_cannot_use_google_calendar_routes(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->get(route('settings.google-calendar.redirect'))->assertForbidden();
        $this->actingAs($student)->delete(route('settings.google-calendar.destroy'))->assertForbidden();
    }
}
