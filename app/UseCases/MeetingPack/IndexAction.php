<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 管理用の面談パック一覧。購入基盤には依存せず、基本情報だけを取得する。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<MeetingPack>
     */
    public function __invoke(?string $keyword, ?MeetingPackStatus $status): LengthAwarePaginator
    {
        $query = MeetingPack::query();

        // 「0」も検索語として扱い、説明文や空白区切りの別キーワードへ検索範囲を広げない。
        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
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
