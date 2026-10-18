<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiChatDailyUsageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatDailyUsage extends Model
{
    /** @use HasFactory<AiChatDailyUsageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'usage_date', 'send_count'];

    protected $casts = [
        'usage_date' => 'date',
        'send_count' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
