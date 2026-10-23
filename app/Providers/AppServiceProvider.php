<?php

declare(strict_types=1);

namespace App\Providers;

use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $command = $payload['data']['command'] ?? null;
            if ($command instanceof \App\Jobs\Meeting\MeetingReminderDatabaseNotificationJob) {
                return ['meeting_reminder' => ['dispatch_id' => $command->dispatchId, 'channel' => 'database', 'dispatch_token' => $command->token, 'queue_attempt_uuid' => $command->queueAttemptUuid]];
            }
            if ($command instanceof \App\Jobs\Meeting\MeetingReminderMailNotificationJob) {
                return ['meeting_reminder' => ['dispatch_id' => $command->dispatchId, 'channel' => 'mail', 'dispatch_token' => $command->token, 'queue_attempt_uuid' => $command->queueAttemptUuid]];
            }
            return [];
        });
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);
    }
}
