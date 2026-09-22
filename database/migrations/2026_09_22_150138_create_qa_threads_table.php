<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('qa_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // 設計案：質問がある資格の物理削除を禁止する。
            $table->foreignUlid('certification_id')
                ->constrained('certifications')
                ->restrictOnDelete();

            // 設計案：質問がある投稿者の物理削除を禁止する。
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('title', 200);
            $table->text('body');

            // 設計案：Enumの保存値もこの値に合わせる。
            $table->string('status', 20)->default('unresolved');

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // 全体の新着順
            $table->index(
                ['created_at', 'id'],
                'qa_threads_created_id_idx',
            );

            // 資格別の新着順
            $table->index(
                ['certification_id', 'created_at', 'id'],
                'qa_threads_cert_created_id_idx',
            );

            // 状態別の新着順
            $table->index(
                ['status', 'created_at', 'id'],
                'qa_threads_status_created_id_idx',
            );

            // 資格・状態で絞り込んだ新着順
            $table->index(
                ['certification_id', 'status', 'created_at', 'id'],
                'qa_threads_cert_status_created_id_idx',
            );

            $table->index('user_id', 'qa_threads_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_threads');
    }
};