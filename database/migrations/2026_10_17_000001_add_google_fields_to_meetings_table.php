<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->ulid('google_connection_id')->nullable()->after('meeting_quota_transaction_id');
            $table->string('google_event_id')->nullable()->after('google_connection_id');
            $table->dateTime('google_event_created_at')->nullable()->after('google_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn(['google_connection_id', 'google_event_id', 'google_event_created_at']);
        });
    }
};
