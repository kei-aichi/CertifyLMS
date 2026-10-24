<?php
declare(strict_types=1);
namespace App\Jobs\Chat;
use App\Enums\UserRole; use App\Enums\UserStatus; use App\Models\ChatMember; use App\Models\ChatMessage;
use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\Middleware\WithoutOverlapping; use Illuminate\Queue\SerializesModels;
final class DispatchChatNotifications implements ShouldQueue {
 use Dispatchable,Queueable,SerializesModels;
 public function __construct(public readonly string $messageId) {}
 public function middleware(): array { return [(new WithoutOverlapping('chat-dispatch:'.$this->messageId))->releaseAfter(10)->expireAfter(300)]; }
 public function handle(): void { $m=ChatMessage::with('sender')->find($this->messageId); $s=$m?->sender; if(!$m||!$s||!in_array($s->role,[UserRole::Student,UserRole::Coach],true)) return; $members=ChatMember::where('chat_room_id',$m->chat_room_id)->where('user_id','!=',$m->sender_user_id)->whereHas('user',fn($q)=>$q->where('status',UserStatus::InProgress->value)->whereIn('role',$s->role===UserRole::Student?[UserRole::Coach->value]:[UserRole::Student->value,UserRole::Coach->value]))->get(); foreach($members as $member){ $mail=$s->role===UserRole::Student||$member->user?->role===UserRole::Student; ChatDatabaseNotificationJob::dispatch($m->id,$member->user_id); if($mail) ChatMailNotificationJob::dispatch($m->id,$member->user_id); } }
}
