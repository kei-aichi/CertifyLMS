<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropIndex('meeting_quota_transactions_related_payment_id_index');
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
            $table->unique('related_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
            $table->dropUnique('meeting_quota_transactions_related_payment_id_unique');
            $table->index('related_payment_id');
        });
    }
};
