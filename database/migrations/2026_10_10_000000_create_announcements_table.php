<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('target_type', 20);
            $table->foreignUlid('target_certification_id')->nullable()->constrained('certifications')->restrictOnDelete();
            $table->foreignUlid('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->string('dispatch_status', 20)->default('processing');
            $table->timestamp('dispatched_at')->nullable();
            $table->string('submission_key')->unique();
            $table->timestamps();

            $table->index('target_type');
            $table->index('dispatch_status');
            $table->index('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
