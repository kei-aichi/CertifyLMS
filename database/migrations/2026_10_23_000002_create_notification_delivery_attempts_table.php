<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::create('notification_delivery_attempts',function(Blueprint $table):void{$table->id();$table->string('event_key',191);$table->string('recipient_id',36);$table->string('channel',32);$table->unsignedTinyInteger('attempts')->default(0);$table->timestamps();$table->unique(['event_key','recipient_id','channel'], 'nda_event_recipient_channel_uq');});} public function down():void{Schema::dropIfExists('notification_delivery_attempts');} };
