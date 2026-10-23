<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminder_queue_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('queue_attempt_uuid')->unique();
            $table->string('dispatch_id', 26);
            $table->string('channel', 16);
            $table->uuid('dispatch_token');
            $table->string('state', 16)->default('preparing');
            $table->string('queue_name', 64)->default('default');
            $table->unsignedBigInteger('queue_job_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['dispatch_id', 'channel', 'created_at'], 'mrrqa_dispatch_channel_created_idx');
            $table->index(['dispatch_id', 'channel', 'state'], 'mrrqa_dispatch_channel_state_idx');
            $table->index('dispatch_token', 'mrrqa_dispatch_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_queue_attempts');
    }
};
