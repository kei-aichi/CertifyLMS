<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enrollment に紐づく個人学習目標。達成状態は achieved_at の有無で表す。
 */
class EnrollmentGoal extends Model
{
    /** @use HasFactory<EnrollmentGoalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }

    /**
     * 未達成、期日昇順(NULLは末尾)、作成日時降順で表示する。
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('target_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
