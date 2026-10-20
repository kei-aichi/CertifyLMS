<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiChatConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiChatConversation extends Model
{
    /** @use HasFactory<AiChatConversationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id', 'enrollment_id', 'section_id', 'source', 'title',
        'auto_title_enabled', 'title_manually_edited_at', 'last_message_at',
    ];

    protected $casts = [
        'auto_title_enabled' => 'boolean',
        'title_manually_edited_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id');
    }

    /** @param Builder<AiChatConversation> $query @return Builder<AiChatConversation> */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** @param Builder<AiChatConversation> $query @return Builder<AiChatConversation> */
    public function scopeForSection(Builder $query, Section $section): Builder
    {
        return $query->where('section_id', $section->id);
    }
}
