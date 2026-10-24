<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { Schema::table('notification_delivery_attempts', function (Blueprint $table): void { $table->unsignedInteger('cycle')->default(0)->after('channel'); $table->dropUnique('nda_event_recipient_channel_uq'); $table->unique(['event_key','recipient_id','channel','cycle'], 'nda_event_recipient_channel_cycle_uq'); }); }
    public function down(): void {
        $hasMultipleCycles = DB::table('notification_delivery_attempts')->select('event_key', 'recipient_id', 'channel')->groupBy('event_key', 'recipient_id', 'channel')->havingRaw('COUNT(DISTINCT cycle) > 1')->exists();
        if ($hasMultipleCycles) throw new RuntimeException('Cannot rollback notification attempt cycles while multiple cycles exist; no data was changed.');
        Schema::table('notification_delivery_attempts', function (Blueprint $table): void { $table->dropUnique('nda_event_recipient_channel_cycle_uq'); $table->dropColumn('cycle'); $table->unique(['event_key','recipient_id','channel'], 'nda_event_recipient_channel_uq'); });
    }
};
