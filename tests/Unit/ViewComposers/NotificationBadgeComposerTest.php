<?php

declare(strict_types=1);

namespace Tests\Unit\ViewComposers;

use App\Models\User;
use App\View\Composers\NotificationBadgeComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Tests\TestCase;

class NotificationBadgeComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_unauthenticated(): void
    {
        $captured = null;
        $view = $this->mockView(function ($name, $value) use (&$captured): void {
            if ($name === 'notificationBadge') {
                $captured = $value;
            }
        });

        (new NotificationBadgeComposer)->compose($view);

        $this->assertSame(0, $captured);
    }

    public function test_returns_only_the_authenticated_users_unread_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
            'data' => ['title' => 'unread'],
            'read_at' => null,
        ]);
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
            'data' => ['title' => 'read'],
            'read_at' => now(),
        ]);
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $other::class,
            'notifiable_id' => $other->getKey(),
            'data' => ['title' => 'other'],
            'read_at' => null,
        ]);

        $this->actingAs($user);
        $captured = null;
        $view = $this->mockView(function ($name, $value) use (&$captured): void {
            if ($name === 'notificationBadge') {
                $captured = $value;
            }
        });

        (new NotificationBadgeComposer)->compose($view);

        $this->assertSame(1, $captured);
    }

    private function mockView(callable $onWith): View
    {
        $view = $this->createMock(View::class);
        $view->method('with')->willReturnCallback(function ($name, $value = null) use ($onWith) {
            $onWith($name, $value);

            return $name;
        });

        return $view;
    }
}
