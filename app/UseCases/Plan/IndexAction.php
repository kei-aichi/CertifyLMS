<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 管理用プラン一覧。契約中の受講生だけを一覧SQL内で集計する。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<Plan>
     */
    public function __invoke(?string $keyword, ?PlanStatus $status): LengthAwarePaginator
    {
        // users Relation のSoftDeletesスコープを維持し、卒業済みを含むactive()は使わない。
        $query = Plan::query()->withCount([
            'users' => fn ($query) => $query->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress),
        ]);

        // 「0」も検索語として扱い、説明文や空白区切りの別キーワードへ検索範囲を広げない。
        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->ordered()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }
}
