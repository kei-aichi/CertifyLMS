<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 資格に紐づく質問。回答数とは独立して解決状態を保持し、削除は物理削除とする。
 *
 * 関連: Certification(対象資格) / User(投稿者) / QaReply(回答)
 * 受講登録していない公開資格にも投稿できるため、Enrollment には紐付けない。
 */
class QaThread extends Model
{
    use HasFactory, HasUlids;

    // 保存可能な属性の定義であり、投稿者・対象資格をクライアントが任意指定できることは意味しない。
    protected $fillable = [
        'certification_id',
        'user_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    // cast は型変換のみ。解決時の日時設定・未解決へ戻す際の NULL 化は更新処理の責務とする。
    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }
}
