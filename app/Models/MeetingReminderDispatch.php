<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 面談リマインダーの送信権利取得記録を表すModel。
 *
 * meeting_id / recipient_idは、対象Modelへの外部キーを持たない履歴ULIDです。
 */
class MeetingReminderDispatch extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'meeting_id',
        'window',
        'recipient_id',
    ];
}
