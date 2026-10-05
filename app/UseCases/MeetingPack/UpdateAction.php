<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 全状態で基本情報を編集できる。公開状態と作成者は基本情報編集では変更しない。
 */
final class UpdateAction
{
    public function __invoke(MeetingPack $plan, User $admin, array $validated): MeetingPack
    {
        return DB::transaction(function () use ($plan, $admin, $validated) {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'meeting_count' => $validated['meeting_count'],
                'price' => $validated['price'],
                'stripe_price_id' => $validated['stripe_price_id'] ?? null,
                // 未指定・null は既存順序を維持し、明示的な 0 は更新する。
                'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
