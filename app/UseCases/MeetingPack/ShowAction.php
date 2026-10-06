<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * 詳細画面の管理者情報を先に取得し、描画時の遅延読み込みを避ける。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        return $plan->load(['createdBy', 'updatedBy']);
    }
}
