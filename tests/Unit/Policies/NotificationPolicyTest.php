<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('notificationAccessStatuses')]
    public function test_notification_access_is_limited_by_user_status(string $factoryState, bool $expected): void
    {
        $user = User::factory()->{$factoryState}()->create();
        $notification = $this->notification($user);
        $policy = new NotificationPolicy;

        self::assertSame($expected, $policy->viewAny($user));
        self::assertSame($expected, $policy->view($user, $notification));
        self::assertSame($expected, $policy->markAsRead($user, $notification));
        self::assertSame($expected, $policy->markAllAsRead($user));
    }

    /** @return array<string, array{string, bool}> */
    public static function notificationAccessStatuses(): array
    {
        return [
            'in_progress' => ['inProgress', true],
            'graduated' => ['graduated', true],
            'invited' => ['invited', false],
            'withdrawn' => ['withdrawn', false],
        ];
    }

    public function test_other_users_notification_is_rejected_for_resource_abilities(): void
    {
        $user = User::factory()->inProgress()->create();
        $other = User::factory()->inProgress()->create();
        $notification = $this->notification($other);
        $policy = new NotificationPolicy;

        self::assertFalse($policy->view($user, $notification));
        self::assertFalse($policy->markAsRead($user, $notification));
    }

    public function test_notification_with_non_user_notifiable_type_is_rejected(): void
    {
        $user = User::factory()->inProgress()->create();
        $notification = $this->notification($user);
        $notification->notifiable_type = 'App\\Models\\OtherNotifiable';
        $policy = new NotificationPolicy;

        self::assertFalse($policy->view($user, $notification));
        self::assertFalse($policy->markAsRead($user, $notification));
    }

    private function notification(User $user): DatabaseNotification
    {
        return new DatabaseNotification([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user::class,
            'notifiable_id' => $user->getKey(),
        ]);
    }
}
