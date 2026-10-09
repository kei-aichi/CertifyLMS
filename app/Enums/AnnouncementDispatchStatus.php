<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementDispatchStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => '処理中または完了未確認',
            self::Succeeded => '配信完了',
            self::Failed => '配信失敗',
        };
    }
}
