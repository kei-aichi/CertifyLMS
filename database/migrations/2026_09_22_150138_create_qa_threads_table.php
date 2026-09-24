<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 質問が残っている資格の物理削除を禁止する。
            $table->foreignUlid('certification_id')
                ->constrained('certifications')
                ->restrictOnDelete();

            // 退会は User の SoftDelete で扱い、投稿を保持する。
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('title', 200);
            $table->text('body');

            $table->string('status', 20)->default('open');

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // 全体の新着順
            $table->index('created_at');

            // 資格別の新着順
            $table->index(['certification_id', 'created_at']);

            // 状態別の新着順
            $table->index(['status', 'created_at']);

            // 資格・状態で絞り込んだ新着順
            $table->index(['certification_id', 'status', 'created_at']);

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_threads');
    }
};
