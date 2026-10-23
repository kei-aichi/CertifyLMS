<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MeetingReminderQueueAttempt extends Model
{
    protected $fillable = [
        'queue_attempt_uuid', 'dispatch_id', 'channel', 'dispatch_token', 'state', 'queue_name',
        'queue_job_id', 'queued_at', 'available_at', 'processing_started_at', 'completed_at',
        'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'queued_at' => 'datetime', 'available_at' => 'datetime', 'processing_started_at' => 'datetime',
        'completed_at' => 'datetime', 'failed_at' => 'datetime',
    ];
}
