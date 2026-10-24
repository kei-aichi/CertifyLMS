<?php
declare(strict_types=1);
namespace Tests\Unit\Jobs\QaReply;
use App\Jobs\QaReply\QaReplyDatabaseNotificationJob; use App\Jobs\QaReply\QaReplyMailNotificationJob; use App\Jobs\QaReply\DispatchQaReplyNotifications; use Illuminate\Contracts\Queue\ShouldQueue; use PHPUnit\Framework\TestCase;
final class QaReplyNotificationJobsTest extends TestCase {public function test_jobs_are_queueable_and_uuid_is_deterministic():void{$this->assertContains(ShouldQueue::class,class_implements(DispatchQaReplyNotifications::class));$this->assertContains(ShouldQueue::class,class_implements(QaReplyDatabaseNotificationJob::class));$this->assertContains(ShouldQueue::class,class_implements(QaReplyMailNotificationJob::class));$a=QaReplyDatabaseNotificationJob::notificationId('reply-1','user-1');$this->assertSame($a,QaReplyDatabaseNotificationJob::notificationId('reply-1','user-1'));$this->assertNotSame($a,QaReplyDatabaseNotificationJob::notificationId('reply-2','user-1'));}}
