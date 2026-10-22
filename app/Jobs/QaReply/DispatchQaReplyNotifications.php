<?php
declare(strict_types=1);
namespace App\Jobs\QaReply;
use App\Enums\UserStatus; use App\Models\QaReply; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\Middleware\WithoutOverlapping; use Illuminate\Queue\SerializesModels;
final class DispatchQaReplyNotifications implements ShouldQueue {use Dispatchable,Queueable,SerializesModels; public function __construct(public readonly string $replyId){} public function middleware():array{return[(new WithoutOverlapping('qa-reply-dispatch:'.$this->replyId))->releaseAfter(10)->expireAfter(300)];} public function handle():void{$r=QaReply::with(['qaThread.user','user'])->find($this->replyId);$author=$r?->qaThread?->user;if(!$r||!$author||$author->id===$r->user_id||$author->status!==UserStatus::InProgress)return;QaReplyDatabaseNotificationJob::dispatch($r->id,$author->id);QaReplyMailNotificationJob::dispatch($r->id,$author->id);}}
