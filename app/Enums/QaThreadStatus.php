<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 質問の解決状態。未回答かどうかは回答数から判定する。
 * 回答がなくても自己解決できるため、「未回答」「対応中」を別の保存状態として持たない。
 */
enum QaThreadStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未解決',
            self::Resolved => '解決済み',
        };
    }
}
