<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QaReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 質問への回答。親質問の更新日時・解決状態は変更しない。
 *
 * 関連: QaThread(親質問) / User(回答者)
 * 質問の updated_at は質問自身の更新を表すため、$touches や親を更新する Model イベントは設けない。
 */
class QaReply extends Model
{
    /** @use HasFactory<QaReplyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'qa_thread_id',
        'user_id',
        'body',
    ];

    /**
     * @return BelongsTo<QaThread, $this>
     */
    public function qaThread(): BelongsTo
    {
        return $this->belongsTo(QaThread::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
