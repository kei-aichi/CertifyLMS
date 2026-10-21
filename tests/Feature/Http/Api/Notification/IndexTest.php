<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_json_401(): void
    {
        $this->getJson(route('api.v1.notifications.index'))
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function test_only_owned_notifications_are_returned_with_unread_count(): void
    {
        $user = User::factory()->inProgress()->create();
        $other = User::factory()->inProgress()->create();
        $mine = $this->notification($user, ['title' => '本人通知', 'message' => '本文'], null, now()->subMinute());
        $this->notification($user, ['title' => '既読', 'message' => '既読'], now(), now()->subMinutes(2));
        $this->notification($other, ['title' => '他人通知', 'message' => '秘密']);

        $this->actingAs($user)->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('meta.tab', 'all')
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonMissing(['title' => '他人通知']);
    }

    public function test_unread_tab_and_invalid_tab_are_validated(): void
    {
        $user = User::factory()->inProgress()->create();
        $this->notification($user, ['title' => '未読', 'message' => '未読']);
        $this->notification($user, ['title' => '既読', 'message' => '既読'], now());

        $this->actingAs($user)->getJson(route('api.v1.notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertJsonPath('meta.tab', 'unread')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonMissing(['title' => '既読']);

        $this->actingAs($user)->getJson(route('api.v1.notifications.index', ['tab' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tab');
    }

    public function test_page_parameter_is_rejected_and_latest_twenty_are_descending(): void
    {
        $user = User::factory()->inProgress()->create();
        for ($i = 1; $i <= 21; $i++) {
            $this->notification($user, ['title' => "通知{$i}", 'message' => "通知{$i}"], null, now()->subMinutes(21 - $i));
        }

        $response = $this->actingAs($user)->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('meta.count', 20);
        $titles = collect($response->json('data'))->pluck('title')->all();
        self::assertSame('通知21', $titles[0]);
        self::assertNotContains('通知1', $titles);

        $this->actingAs($user)->getJson(route('api.v1.notifications.index', ['page' => 2]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');
    }

    public function test_graduated_is_allowed_and_invited_and_withdrawn_are_forbidden(): void
    {
        foreach (['graduated' => 200, 'invited' => 403, 'withdrawn' => 403] as $state => $status) {
            $user = User::factory()->{$state}()->create();
            $this->notification($user, ['title' => $state, 'message' => $state]);

            $this->actingAs($user)->getJson(route('api.v1.notifications.index'))
                ->assertStatus($status);
        }
    }

    public function test_notification_data_fallbacks_and_safe_redirect_are_returned(): void
    {
        $user = User::factory()->inProgress()->create();
        $messageNotification = $this->notification($user, [
            'notification_type' => 'chat_message_received',
            'title' => 'タイトル',
            'message' => 'message優先',
            'body_preview' => 'preview',
            'redirect_route' => 'notifications.show',
            'redirect_parameters' => ['notification' => 'other-id'],
        ]);
        $this->notification($user, [
            'notification_type' => 'admin_announcement',
            'body' => 'body fallback',
            'redirect_url' => 'https://evil.example.test',
            'redirect_route' => 'invalid.route',
        ]);

        $data = $this->actingAs($user)->getJson(route('api.v1.notifications.index'))->assertOk()->json('data');
        $byTitle = collect($data)->keyBy('title');
        self::assertSame('message優先', $byTitle['タイトル']['preview']);
        self::assertSame('body fallback', $byTitle['通知']['preview']);
        self::assertSame(route('notifications.index'), $byTitle['通知']['redirect_url']);
        self::assertStringStartsWith(route('notifications.show', $messageNotification), $byTitle['タイトル']['redirect_url']);
        self::assertStringNotContainsString('evil.example.test', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function notification(User $user, array $data, ?\DateTimeInterface $readAt = null, ?\DateTimeInterface $createdAt = null): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
            'data' => $data,
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
