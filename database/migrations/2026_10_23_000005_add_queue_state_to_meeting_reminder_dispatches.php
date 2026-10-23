<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_reminder_dispatches', function (Blueprint $table): void {
            // R-2 will explicitly opt new Queue records into legacy=false/pending.
            // Until then the existing synchronous path must remain non-queueable.
            $table->boolean('legacy')->default(true)->after('recipient_id');
            $table->string('database_status', 16)->nullable()->default(null)->after('legacy');
            $table->string('mail_status', 16)->nullable()->default(null)->after('database_status');
            $table->uuid('database_dispatch_token')->nullable()->after('mail_status');
            $table->uuid('mail_dispatch_token')->nullable()->after('database_dispatch_token');
            $table->timestamp('database_queued_at')->nullable()->after('mail_dispatch_token');
            $table->timestamp('mail_queued_at')->nullable()->after('database_queued_at');
            $table->timestamp('database_processing_started_at')->nullable()->after('mail_queued_at');
            $table->timestamp('mail_processing_started_at')->nullable()->after('database_processing_started_at');
            $table->timestamp('database_completed_at')->nullable()->after('mail_processing_started_at');
            $table->timestamp('mail_completed_at')->nullable()->after('database_completed_at');

            $table->index(['database_status', 'database_queued_at'], 'mr_dispatch_database_state_idx');
            $table->index(['mail_status', 'mail_queued_at'], 'mr_dispatch_mail_state_idx');
        });

        // Rows created by the pre-queue S-B-09 implementation have no reliable
        // per-channel result. Close them as legacy history and never resend them.
        Schema::getConnection()->table('meeting_reminder_dispatches')->update([
            'legacy' => true,
            'database_status' => null,
            'mail_status' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('meeting_reminder_dispatches', function (Blueprint $table): void {
            $table->dropIndex('mr_dispatch_database_state_idx');
            $table->dropIndex('mr_dispatch_mail_state_idx');
            $table->dropColumn([
                'legacy', 'database_status', 'mail_status',
                'database_dispatch_token', 'mail_dispatch_token',
                'database_queued_at', 'mail_queued_at',
                'database_processing_started_at', 'mail_processing_started_at',
                'database_completed_at', 'mail_completed_at',
            ]);
        });
    }
};
