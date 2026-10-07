<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackEmbedSession extends Model
{
    use HasFactory;

    protected $table = 'fb_embed_sessions';

    protected $fillable = [
        'embed_id',
        'token_hash',
        'respondent_id',
        'respondent_hash',
        'metadata',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * The embed configuration this session belongs to.
     *
     * @return BelongsTo<FeedbackEmbed, FeedbackEmbedSession>
     */
    public function embed(): BelongsTo
    {
        return $this->belongsTo(FeedbackEmbed::class, 'embed_id');
    }

    /**
     * Submissions created from this session.
     *
     * @return HasMany<FbSubmission, FeedbackEmbedSession>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FbSubmission::class, 'embed_session_id');
    }

    /**
     * Scope a query to only include valid, unexpired, and unused sessions.
     *
     * @param  Builder<FeedbackEmbedSession>  $query
     * @return Builder<FeedbackEmbedSession>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now())
            ->whereNull('used_at');
    }

    /**
     * Determine if the session is currently valid and unused.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Atomically mark the session as used.
     */
    public function markAsUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
