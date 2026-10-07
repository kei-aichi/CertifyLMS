<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_only_their_notifications_and_filter_unread(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->notification($user, '自分の通知');
        $this->notification($user, '既読通知', now());
        $this->notification($other, '他人の通知');

        $this->actingAs($user)
            ->get(route('notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertViewHas('tab', 'unread')
            ->assertSeeText('自分の通知')
            ->assertDontSeeText('既読通知')
            ->assertDontSeeText('他人の通知');

        $this->assertNotNull($mine->id);
    }

    public function test_notifications_are_newest_first_and_paginated_with_tab_preserved(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 21; $i++) {
            $this->notification($user, "通知{$i}", null, now()->subMinutes(21 - $i));
        }

        $response = $this->actingAs($user)
            ->get(route('notifications.index', ['tab' => 'unread']))
            ->assertOk()
            ->assertSeeText('通知21')
            ->assertSee('tab=unread');
        $this->assertDoesNotMatchRegularExpression('~>\s*通知1\s*<~u', $response->getContent());

        $this->actingAs($user)
            ->get(route('notifications.index', ['tab' => 'unread', 'page' => 2]))
            ->assertOk()
            ->assertSeeText('通知1');
    }

    public function test_user_can_mark_owned_notification_as_read_and_repeat_safely(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification($user, '通知');

        $response = $this->actingAs($user)->post(route('notifications.markAsRead', $notification));
        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($user)->post(route('notifications.markAsRead', $notification))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $notification = $this->notification($other, '他人の通知');

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_user_can_mark_all_owned_unread_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notification($user, '未読1');
        $this->notification($user, '未読2');
        $read = $this->notification($user, '既読', now());
        $otherNotification = $this->notification($other, '他人');

        $this->actingAs($user)
            ->post(route('notifications.markAllAsRead'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
        $this->assertNotNull($read->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_graduated_user_can_view_and_read_existing_notifications(): void
    {
        $user = User::factory()->graduated()->create();
        $notification = $this->notification($user, '卒業後も閲覧可能');

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSeeText('卒業後も閲覧可能');
        $this->actingAs($user)->post(route('notifications.markAsRead', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        $bulk = $this->notification($user, '卒業後も一括既読可能');
        $this->actingAs($user)->post(route('notifications.markAllAsRead'))->assertRedirect(route('notifications.index'));
        $this->assertNotNull($bulk->fresh()->read_at);
    }

    public function test_arbitrary_redirect_url_in_notification_data_is_not_used(): void
    {
        $user = User::factory()->create();
        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
            'data' => ['title' => '通知', 'redirect_url' => 'https://example.com/unsafe'],
            'read_at' => null,
        ]);

        $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_missing_notification_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('notifications.markAsRead', ['notification' => (string) Str::uuid()]))
            ->assertNotFound();
    }

    private function notification(User $user, string $title, ?\DateTimeInterface $readAt = null, ?\DateTimeInterface $createdAt = null): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
            'data' => ['title' => $title, 'message' => $title],
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
