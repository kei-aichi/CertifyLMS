<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチ本人が連携した Google Calendar の認証情報。
 *
 * トークンは画面やログへ出力しない。暗号化保存は S-A-01 の対象外とする。
 */
class GoogleCredential extends Model
{
    /** @use HasFactory<GoogleCredentialFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'coach_id',
        'calendar_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
