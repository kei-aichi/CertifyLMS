<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 開発環境でAIチャット画面を確認するための固定データ。 */
final class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('AiChatSeeder: local/testing 環境以外では実行しません。');

            return;
        }

        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        if ($student === null) {
            $this->command?->warn('AiChatSeeder: 固定受講生が存在しません。');

            return;
        }

        $section = Section::query()
            ->whereHas('chapter.part', fn ($query) => $query->whereHas('certification.enrollments', fn ($enrollments) => $enrollments->where('user_id', $student->id)))
            ->orderBy('created_at')
            ->first();

        if ($section === null) {
            $this->command?->warn('AiChatSeeder: 固定受講生向けSectionが存在しません。');

            return;
        }
        $enrollment = Enrollment::query()->where('user_id', $student->id)->where('certification_id', $section->chapter->part->certification_id)->first();

        DB::transaction(function () use ($student, $section, $enrollment): void {
            $sectionConversation = $this->conversation($student, 'AI相談：Sectionサンプル', $section->id, $enrollment?->id);
            $this->completedMessages($sectionConversation);

            $generalConversation = $this->conversation($student, 'AI相談：一般質問', null, null);
            $this->completedMessages($generalConversation);

            $errorConversation = $this->conversation($student, 'AI相談：エラー履歴', null, null);
            $this->errorMessage($errorConversation);
        });
    }

    private function conversation(User $student, string $title, ?string $sectionId, ?string $enrollmentId): AiChatConversation
    {
        return AiChatConversation::query()->firstOrCreate(
            ['user_id' => $student->id, 'title' => $title],
            ['section_id' => $sectionId, 'enrollment_id' => $enrollmentId, 'source' => 'full-screen']
        );
    }

    private function completedMessages(AiChatConversation $conversation): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $user = $conversation->messages()->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => 'この教材の要点を教えてください。']);
        $conversation->messages()->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Completed, 'content' => 'このSectionでは、重要な概念を例題と一緒に確認できます。', 'model' => 'seed-data', 'input_tokens' => 24, 'output_tokens' => 18, 'response_time_ms' => 420]);
        $conversation->update(['last_message_at' => $user->created_at]);
    }

    private function errorMessage(AiChatConversation $conversation): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $conversation->messages()->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '難しい質問を送信します。']);
        $conversation->messages()->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Error, 'content' => '', 'error_detail' => 'gemini_api_error']);
        $conversation->update(['last_message_at' => now()]);
    }
}
