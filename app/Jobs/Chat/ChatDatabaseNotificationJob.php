<?php
declare(strict_types=1);
namespace App\Jobs\Chat;
use App\Models\ChatMessage; use App\Services\Notifications\NotificationAttemptTracker; use App\Models\User; use App\Notifications\ChatMessageReceivedNotification; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\Middleware\WithoutOverlapping; use Illuminate\Queue\SerializesModels; use Illuminate\Support\Facades\Notification; use Throwable;
final class ChatDatabaseNotificationJob implements ShouldQueue {
 use Dispatchable,Queueable,SerializesModels,InteractsWithQueue;
 public int $tries=0;
 public function __construct(public readonly string $messageId,public readonly string $recipientId){}
 public function backoff():array{return[60,300];}
 public function middleware():array{return[(new WithoutOverlapping('chat-db:'.$this->messageId.':'.$this->recipientId))->releaseAfter(10)->expireAfter(300)];}
 public function handle():void{$u=User::find($this->recipientId);$m=ChatMessage::find($this->messageId);if(!$u||!$m||$u->status->value!=='in_progress')return;$id=self::notificationId($this->messageId,$this->recipientId);if($u->notifications()->whereKey($id)->exists())return;$count=app(NotificationAttemptTracker::class)->increment('chat:'.$this->messageId, $this->recipientId, 'database');if($count>3){$this->fail(new \RuntimeException('notification delivery attempts exceeded'));return;}try{$n=new ChatMessageReceivedNotification($m,false);$n->id=$id;Notification::sendNow($u,$n,['database']);}catch(Throwable $e){if($count>=3){$this->fail($e);return;}throw $e;}}
 public static function notificationId(string $m,string $u):string{$h=hash('sha256','chat:'.$m.':'.$u);return substr($h,0,8).'-'.substr($h,8,4).'-5'.substr($h,13,3).'-'.substr($h,16,4).'-'.substr($h,20,12);}
}
