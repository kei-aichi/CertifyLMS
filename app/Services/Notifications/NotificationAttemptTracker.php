<?php
declare(strict_types=1);
namespace App\Services\Notifications;
use App\Models\NotificationDeliveryAttempt; use Illuminate\Database\QueryException; use Illuminate\Support\Facades\DB;
final class NotificationAttemptTracker { public function increment(string $eventKey,string $recipientId,string $channel,int $cycle=0):int{return DB::transaction(function()use($eventKey,$recipientId,$channel,$cycle):int{try{NotificationDeliveryAttempt::create(['event_key'=>$eventKey,'recipient_id'=>$recipientId,'channel'=>$channel,'cycle'=>$cycle,'attempts'=>0]);}catch(QueryException $e){if($e->getCode()!=='23000' || (int)($e->errorInfo[1] ?? 0)!==1062)throw $e;} $row=NotificationDeliveryAttempt::where(['event_key'=>$eventKey,'recipient_id'=>$recipientId,'channel'=>$channel,'cycle'=>$cycle])->lockForUpdate()->firstOrFail();if($row->attempts>=3)return (int)$row->attempts;$row->increment('attempts');return (int)$row->attempts;});} }
