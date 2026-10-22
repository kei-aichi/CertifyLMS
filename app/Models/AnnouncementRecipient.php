<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRecipient extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'announcement_id',
        'recipient_id',
        'title_snapshot',
        'body_snapshot',
        'database_status',
        'mail_status',
        'database_dispatch_token',
        'mail_dispatch_token',
        'database_claimed_at',
        'mail_claimed_at',
        'database_queued_at',
        'mail_queued_at',
        'database_processing_started_at',
        'mail_processing_started_at',
        'database_completed_at',
        'mail_completed_at',
    ];

    protected $casts = [
        'database_claimed_at' => 'datetime',
        'mail_claimed_at' => 'datetime',
        'database_queued_at' => 'datetime',
        'mail_queued_at' => 'datetime',
        'database_processing_started_at' => 'datetime',
        'mail_processing_started_at' => 'datetime',
        'database_completed_at' => 'datetime',
        'mail_completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Announcement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
