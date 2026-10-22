<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_json_401(): void
    {
        $this->getJson(route('api.v1.notifications.index'))
            ->assertUnauthorized()
            ->assertJson([
                'error_code' => 'UNAUTHENTICATED',
                'status' => 401,
            ])
            ->assertJsonStructure(['message', 'error_code', 'status']);
    }

    public function test_unauthenticated_mark_as_read_returns_json_401(): void
    {
        $user = User::factory()->inProgress()->create();
        $notification = $this->notification($user, ['message' => '本文']);

        $this->postJson(route('api.v1.notifications.markAsRead', $notification))
            ->assertUnauthorized();
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
            ->assertJson([
                'error_code' => 'VALIDATION_ERROR',
                'status' => 422,
            ])
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

    public function test_owner_can_mark_notification_as_read_and_repeat_safely(): void
    {
        $user = User::factory()->inProgress()->create();
        $notification = $this->notification($user, ['message' => '本文']);

        $response = $this->actingAs($user)->postJson(
            route('api.v1.notifications.markAsRead', $notification),
        );

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'type', 'notification_type', 'title', 'preview',
                'created_at', 'relative_time', 'read_at', 'is_unread', 'redirect_url',
            ], 'meta' => ['unread_count']])
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.is_unread', false)
            ->assertJsonPath('meta.unread_count', 0);
        $firstReadAt = $notification->fresh()->read_at;
        self::assertNotNull($firstReadAt);
        self::assertSame($firstReadAt->toIso8601String(), $response->json('data.read_at'));
        self::assertSame($firstReadAt->toIso8601String(), Carbon::parse($response->json('data.read_at'))->toIso8601String());

        $second = $this->actingAs($user)->postJson(
            route('api.v1.notifications.markAsRead', $notification),
        );
        $second->assertOk()
            ->assertJsonPath('data.is_unread', false)
            ->assertJsonPath('meta.unread_count', 0);
        self::assertSame($firstReadAt->toIso8601String(), $notification->fresh()->read_at?->toIso8601String());
    }

    public function test_marking_one_of_multiple_unread_notifications_updates_unread_count(): void
    {
        $user = User::factory()->inProgress()->create();
        $notification = $this->notification($user, ['message' => '未読1']);
        $this->notification($user, ['message' => '未読2']);

        $this->actingAs($user)
            ->postJson(route('api.v1.notifications.markAsRead', $notification))
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1);

        self::assertNotNull($notification->fresh()->read_at);
    }

    public function test_other_user_notification_and_missing_notification_are_rejected(): void
    {
        $user = User::factory()->inProgress()->create();
        $other = User::factory()->inProgress()->create();
        $notification = $this->notification($other, ['message' => '秘密']);

        $this->actingAs($user)->postJson(route('api.v1.notifications.markAsRead', $notification))
            ->assertForbidden()
            ->assertJson([
                'error_code' => 'NOTIFICATION_ACCESS_DENIED',
                'status' => 403,
            ])
            ->assertJsonMissing(['message' => '秘密']);
        self::assertNull($notification->fresh()->read_at);

        $this->actingAs($user)->postJson(route('api.v1.notifications.markAsRead', ['notification' => (string) Str::uuid()]))
            ->assertNotFound()
            ->assertJson([
                'error_code' => 'NOT_FOUND',
                'status' => 404,
            ]);
    }

    public function test_notification_status_controls_mark_as_read_access(): void
    {
        foreach (['inProgress' => 200, 'graduated' => 200, 'invited' => 403, 'withdrawn' => 403] as $state => $status) {
            $user = User::factory()->{$state}()->create();
            $notification = $this->notification($user, ['message' => $state]);

            $response = $this->actingAs($user)->postJson(
                route('api.v1.notifications.markAsRead', $notification),
            );
            $response->assertStatus($status);

            if ($status === 403) {
                self::assertNull($notification->fresh()->read_at);
            } else {
                self::assertNotNull($notification->fresh()->read_at);
            }
        }
    }

    public function test_owner_can_mark_all_unread_notifications_as_read(): void
    {
        $user = User::factory()->inProgress()->create();
        $unreadOne = $this->notification($user, ['message' => '未読1']);
        $unreadTwo = $this->notification($user, ['message' => '未読2']);
        $readAt = now()->subMinute();
        $alreadyRead = $this->notification($user, ['message' => '既読'], $readAt);
        $other = $this->notification(User::factory()->inProgress()->create(), ['message' => '他人の秘密']);

        $response = $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'));

        $response->assertOk()->assertExactJson(['data' => ['updated_count' => 2, 'unread_count' => 0]]);
        self::assertNotNull($unreadOne->fresh()->read_at);
        self::assertNotNull($unreadTwo->fresh()->read_at);
        self::assertSame($readAt->toIso8601String(), $alreadyRead->fresh()->read_at?->toIso8601String());
        self::assertNull($other->fresh()->read_at);
    }

    public function test_mark_all_is_idempotent_on_second_request(): void
    {
        $user = User::factory()->inProgress()->create();
        $notifications = [
            $this->notification($user, ['message' => '未読1']),
            $this->notification($user, ['message' => '未読2']),
        ];

        $first = $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'));
        $first->assertOk()->assertExactJson(['data' => ['updated_count' => 2, 'unread_count' => 0]]);
        $firstReadAt = array_map(
            static fn (DatabaseNotification $notification): string => $notification->fresh()->read_at->toIso8601String(),
            $notifications,
        );

        $second = $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'));
        $second->assertOk()->assertExactJson(['data' => ['updated_count' => 0, 'unread_count' => 0]]);
        $secondReadAt = array_map(
            static fn (DatabaseNotification $notification): string => $notification->fresh()->read_at->toIso8601String(),
            $notifications,
        );

        self::assertSame($firstReadAt, $secondReadAt);
    }

    public function test_graduated_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->graduated()->create();
        $notification = $this->notification($user, ['message' => '卒業後の未読通知']);

        $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'))
            ->assertOk()
            ->assertExactJson(['data' => ['updated_count' => 1, 'unread_count' => 0]]);

        self::assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_is_safe_when_there_are_no_unread_notifications(): void
    {
        $user = User::factory()->inProgress()->create();
        $readAt = now()->subMinute();
        $notification = $this->notification($user, ['message' => '既読'], $readAt);

        $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'))
            ->assertOk()
            ->assertExactJson(['data' => ['updated_count' => 0, 'unread_count' => 0]]);
        self::assertSame($readAt->toIso8601String(), $notification->fresh()->read_at?->toIso8601String());
    }

    public function test_mark_all_requires_authentication_and_allowed_status(): void
    {
        $this->postJson(route('api.v1.notifications.markAllAsRead'))->assertUnauthorized();

        foreach (['invited', 'withdrawn'] as $state) {
            $user = User::factory()->{$state}()->create();
            $notification = $this->notification($user, ['message' => '機密本文']);

            $this->actingAs($user)->postJson(route('api.v1.notifications.markAllAsRead'))
                ->assertForbidden()
                ->assertJsonMissing(['message' => '機密本文']);
            self::assertNull($notification->fresh()->read_at);
        }
    }

    public function test_admin_can_read_only_their_own_notifications_through_the_api(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->inProgress()->create();
        $mine = $this->notification($admin, ['title' => '管理者本人', 'message' => '本人通知']);
        $otherNotification = $this->notification($other, ['title' => '他人通知', 'message' => '秘密']);

        $this->actingAs($admin)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $mine->id])
            ->assertJsonMissing(['id' => $otherNotification->id])
            ->assertJsonMissing(['title' => '他人通知']);

        $this->actingAs($admin)
            ->postJson(route('api.v1.notifications.markAsRead', $mine))
            ->assertOk()
            ->assertJsonPath('data.is_unread', false);

        $adminUnread = $this->notification($admin, ['title' => '管理者未読', 'message' => '一括既読対象']);
        $this->actingAs($admin)
            ->postJson(route('api.v1.notifications.markAllAsRead'))
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.unread_count', 0);

        self::assertNotNull($adminUnread->fresh()->read_at);
        self::assertNull($otherNotification->fresh()->read_at);
    }

    public function test_admin_cannot_mark_another_users_notification_as_read(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->inProgress()->create();
        $notification = $this->notification($other, ['message' => '他人の秘密']);

        $this->actingAs($admin)
            ->postJson(route('api.v1.notifications.markAsRead', $notification))
            ->assertForbidden()
            ->assertJson([
                'error_code' => 'NOTIFICATION_ACCESS_DENIED',
                'status' => 403,
            ])
            ->assertJsonMissing(['message' => '他人の秘密']);

        self::assertNull($notification->fresh()->read_at);
    }

    public function test_admin_has_no_notification_bell_but_keeps_sidebar_notification_link(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('data-notification-popover-root')
            ->assertSee('href="'.route('notifications.index').'"', false);
    }

    public function test_sanctum_csrf_cookie_is_available_without_authentication(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        self::assertNotNull($response->getCookie('XSRF-TOKEN'));
    }

    public function test_cookie_session_authentication_can_access_notification_api(): void
    {
        $user = User::factory()->inProgress()->create([
            'email' => 'notification-cookie@example.test',
            'password' => bcrypt('secret-pass'),
        ]);

        $login = $this->post('/login', [
            'email' => 'notification-cookie@example.test',
            'password' => 'secret-pass',
        ]);
        $login->assertRedirect();

        $sessionCookie = $login->getCookie(config('session.cookie'));
        self::assertNotNull($sessionCookie);

        $this->withCookie(config('session.cookie'), $sessionCookie->getValue())
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk();
    }

    public function test_notification_post_with_cookie_session_csrf_token_succeeds(): void
    {
        $user = User::factory()->inProgress()->create();
        $notification = $this->notification($user, ['message' => 'CSRF対象']);
        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $sessionCookie = $login->getCookie(config('session.cookie'));
        self::assertNotNull($sessionCookie);

        $this->withMiddleware()
            ->withCookie(config('session.cookie'), $sessionCookie->getValue())
            ->withHeaders([
                'Origin' => 'http://localhost',
                'Referer' => 'http://localhost/',
            ])
            ->withHeader('X-CSRF-TOKEN', session()->token())
            ->postJson(route('api.v1.notifications.markAsRead', $notification))
            ->assertOk()
            ->assertJsonPath('data.is_unread', false);
    }

    public function test_unexpected_notification_api_exception_returns_safe_500_json(): void
    {
        $request = Request::create(
            '/api/v1/notifications',
            'GET',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        $response = app(ExceptionHandler::class)->render($request, new \RuntimeException('secret details'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([
            'message' => 'サーバーエラーが発生しました。',
            'error_code' => 'INTERNAL_SERVER_ERROR',
            'status' => 500,
        ], $response->getData(true));
    }

    public function test_notification_api_rate_limit_returns_common_429_json(): void
    {
        $request = Request::create(
            '/api/v1/notifications',
            'GET',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        $response = app(ExceptionHandler::class)->render(
            $request,
            new ThrottleRequestsException('Too many requests.'),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'message' => 'リクエスト過多です。しばらく時間を空けて再試行してください。',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'status' => 429,
        ], $response->getData(true));
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
