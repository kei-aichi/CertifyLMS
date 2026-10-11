<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminder_dispatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('meeting_id');
            $table->string('window', 32);
            $table->ulid('recipient_id');
            $table->timestamps();

            $table->unique(
                ['meeting_id', 'window', 'recipient_id'],
                'meeting_reminder_dispatches_meeting_window_recipient_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_dispatches');
    }
};
