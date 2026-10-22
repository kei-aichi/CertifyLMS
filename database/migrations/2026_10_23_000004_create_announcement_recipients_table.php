<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('announcement_id')->constrained('announcements')->restrictOnDelete();
            $table->foreignUlid('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('title_snapshot', 200);
            $table->text('body_snapshot');
            $table->string('database_status', 20)->default('pending');
            $table->string('mail_status', 20)->default('pending');
            $table->string('database_dispatch_token', 100)->nullable();
            $table->string('mail_dispatch_token', 100)->nullable();
            $table->timestamp('database_claimed_at')->nullable();
            $table->timestamp('mail_claimed_at')->nullable();
            $table->timestamp('database_queued_at')->nullable();
            $table->timestamp('mail_queued_at')->nullable();
            $table->timestamp('database_processing_started_at')->nullable();
            $table->timestamp('mail_processing_started_at')->nullable();
            $table->timestamp('database_completed_at')->nullable();
            $table->timestamp('mail_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'recipient_id']);
            $table->index(
                ['announcement_id', 'database_status', 'database_queued_at'],
                'ar_announcement_database_queue_idx',
            );
            $table->index(
                ['announcement_id', 'mail_status', 'mail_queued_at'],
                'ar_announcement_mail_queue_idx',
            );
            $table->index('database_claimed_at', 'ar_database_claimed_idx');
            $table->index('mail_claimed_at', 'ar_mail_claimed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
