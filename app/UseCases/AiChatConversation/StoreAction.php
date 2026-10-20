<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StoreAction
{
    public function __invoke(User $user, array $data): AiChatConversation
    {
        $section = isset($data['section_id']) ? Section::query()->with('chapter.part.certification')->findOrFail($data['section_id']) : null;
        $enrollment = isset($data['enrollment_id']) ? $user->enrollments()->whereKey($data['enrollment_id'])->firstOrFail() : null;
        if ($section !== null) {
            $this->assertSection($user, $section);
            $certificationId = $section->chapter->part->certification_id;
            if ($enrollment !== null && $enrollment->certification_id !== $certificationId) {
                abort(422, 'Sectionと受講登録の資格が一致しません。');
            }
            $enrollment ??= $user->enrollments()->where('certification_id', $certificationId)
                ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])->latest()->first();
            if ($enrollment === null) {
                abort(403, 'Sectionを利用できる受講登録がありません。');
            }
        }
        if ($enrollment !== null && ! in_array($enrollment->status, [EnrollmentStatus::Learning, EnrollmentStatus::Passed], true)) {
            abort(403, '受講登録を利用できません。');
        }

        return DB::transaction(function () use ($user, $data, $section, $enrollment): AiChatConversation {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($section !== null && ! ($data['force_new'] ?? false)) {
                $reuseQuery = AiChatConversation::query()
                    ->where('user_id', $user->getKey())
                    ->where('section_id', $section->getKey())
                    ->latest('last_message_at')->latest('id');
                $existing = $reuseQuery->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            return AiChatConversation::query()->create([
                'user_id' => $user->id, 'enrollment_id' => $enrollment?->id, 'section_id' => $section?->id,
                'source' => $data['source'], 'title' => '新しい相談',
                'auto_title_enabled' => config('ai-chat.auto_title_enabled', true),
            ]);
        });
    }

    private function assertSection(User $user, Section $section): void
    {
        $chapter = $section->chapter;
        $part = $chapter?->part;
        $certification = $part?->certification;
        if ($section->status !== ContentStatus::Published || $chapter?->status !== ContentStatus::Published || $part?->status !== ContentStatus::Published || $certification?->status !== CertificationStatus::Published || ! Gate::forUser($user)->allows('learning.section.view', $section)) {
            abort(404, '教材Sectionが見つかりません。');
        }
    }
}
