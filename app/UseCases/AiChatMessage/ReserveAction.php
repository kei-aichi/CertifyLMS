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
                // Step 5-2 will recover stale rows under these same locks. Until then,
                // rejecting every existing pending row prevents duplicate in-flight work.
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
}
