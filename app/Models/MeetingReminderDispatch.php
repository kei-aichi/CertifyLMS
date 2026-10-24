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

    protected $attributes = [
        'legacy' => true,
        'database_status' => null,
        'mail_status' => null,
    ];

    protected $fillable = [
        'meeting_id',
        'window',
        'recipient_id',
        'legacy',
        'database_status',
        'mail_status',
        'database_dispatch_token',
        'mail_dispatch_token',
        'database_queued_at',
        'mail_queued_at',
        'database_processing_started_at',
        'mail_processing_started_at',
        'database_completed_at',
        'mail_completed_at',
    ];

    protected $casts = [
        'legacy' => 'boolean',
        'database_queued_at' => 'datetime',
        'mail_queued_at' => 'datetime',
        'database_processing_started_at' => 'datetime',
        'mail_processing_started_at' => 'datetime',
        'database_completed_at' => 'datetime',
        'mail_completed_at' => 'datetime',
    ];

    public function isLegacy(): bool
    {
        return $this->legacy === true;
    }

    public function isQueueable(): bool
    {
        return ! $this->isLegacy();
    }
}
