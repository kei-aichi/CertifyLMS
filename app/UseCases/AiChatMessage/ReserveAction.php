<?php

declare(strict_types=1);

namespace App\UseCases\AiChatMessage;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatPendingException;
use App\Exceptions\AiChat\GeminiApiKeyMissingException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReserveAction
{
    public function __invoke(User $user, AiChatConversation $conversation, string $content): AiChatMessage
    {
        if (blank(config('ai-chat.gemini.api_key'))) {
            throw new GeminiApiKeyMissingException;
        }

        $limit = (int) config('ai-chat.daily_limit', 20);
        $today = now(config('app.timezone', 'Asia/Tokyo'))->toDateString();

        $this->recoverStalePending($user, $conversation);

        return DB::transaction(function () use ($user, $conversation, $content, $limit, $today): AiChatMessage {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $lockedConversation = AiChatConversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($lockedUser)->authorize('sendMessage', $lockedConversation);

            $pending = $lockedConversation->messages()
                ->where('role', AiChatMessageRole::User->value)
                ->where('status', AiChatMessageStatus::Pending->value)
                ->first();

            if ($pending !== null) {
                throw new AiChatPendingException;
            }

            $usage = AiChatDailyUsage::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereDate('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if ($usage === null) {
                $usage = AiChatDailyUsage::query()->create([
                    'user_id' => $lockedUser->getKey(),
                    'usage_date' => $today,
                    'send_count' => 0,
                ]);
            }

            if ($usage->send_count >= $limit) {
                throw new AiChatDailyLimitExceededException;
            }

            $usage->increment('send_count');

            return $lockedConversation->messages()->create([
                'role' => AiChatMessageRole::User,
                'status' => AiChatMessageStatus::Pending,
                'content' => $content,
            ]);
        });
    }

    public function recoverStale(User $user, AiChatConversation $conversation): void
    {
        $this->recoverStalePending($user, $conversation);
    }

    private function recoverStalePending(User $user, AiChatConversation $conversation): void
    {
        DB::transaction(function () use ($user, $conversation): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $lockedConversation = AiChatConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedUser)->authorize('sendMessage', $lockedConversation);

            $pending = $lockedConversation->messages()
                ->where('role', AiChatMessageRole::User->value)
                ->where('status', AiChatMessageStatus::Pending->value)
                ->first();
            if ($pending === null) {
                return;
            }

            $timeout = max(1, (int) config('ai-chat.pending_timeout_seconds', 120));
            if ($pending->updated_at !== null && $pending->updated_at->gt(now()->subSeconds($timeout))) {
                throw new AiChatPendingException;
            }

            $updated = AiChatMessage::query()
                ->whereKey($pending->getKey())
                ->where('status', AiChatMessageStatus::Pending->value)
                ->update([
                    'status' => AiChatMessageStatus::Completed->value,
                    'error_detail' => 'stale_pending',
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $lockedConversation->messages()->create([
                    'role' => AiChatMessageRole::Assistant,
                    'status' => AiChatMessageStatus::Error,
                    'content' => '前回のAI応答が完了しなかったため、処理を終了しました。',
                    'error_detail' => 'stale_pending',
                ]);
            }
        });
    }
}
