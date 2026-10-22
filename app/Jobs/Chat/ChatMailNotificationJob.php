<?php
declare(strict_types=1);
namespace App\Jobs\Chat;
use App\Models\ChatMessage; use App\Services\Notifications\NotificationAttemptTracker; use App\Models\User; use App\Notifications\ChatMessageReceivedNotification; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\Middleware\WithoutOverlapping; use Illuminate\Queue\SerializesModels; use Illuminate\Support\Facades\Notification; use Throwable;
final class ChatMailNotificationJob implements ShouldQueue {
 use Dispatchable,Queueable,SerializesModels,InteractsWithQueue;
 public int $tries=0;
 public function __construct(public readonly string $messageId,public readonly string $recipientId){}
 public function backoff():array{return[60,300];}
 public function middleware():array{return[(new WithoutOverlapping('chat-mail:'.$this->messageId.':'.$this->recipientId))->releaseAfter(10)->expireAfter(300)];}
 public function handle():void{$u=User::find($this->recipientId);$m=ChatMessage::find($this->messageId);if(!$u||!$m||$u->status->value!=='in_progress')return;$count=app(NotificationAttemptTracker::class)->increment('chat:'.$this->messageId, $this->recipientId, 'mail');if($count>3){$this->fail(new \RuntimeException('notification delivery attempts exceeded'));return;}try{Notification::sendNow($u,new ChatMessageReceivedNotification($m,true),['mail']);}catch(Throwable $e){if($count>=3){$this->fail($e);return;}throw $e;}}
}
