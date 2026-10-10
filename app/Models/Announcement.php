<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'created_by',
        'target_type',
        'target_certification_id',
        'target_user_id',
        'title',
        'body',
        'dispatched_count',
        'dispatch_status',
        'dispatched_at',
        'submission_key',
    ];

    protected $casts = [
        'target_type' => AnnouncementTargetType::class,
        'dispatch_status' => AnnouncementDispatchStatus::class,
        'dispatched_count' => 'integer',
        'dispatched_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** @return BelongsTo<Certification, $this> */
    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'target_certification_id');
    }

    /** @return BelongsTo<Certification, $this> */
    public function certification(): BelongsTo
    {
        return $this->targetCertification();
    }
}
