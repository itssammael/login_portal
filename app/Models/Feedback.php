<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feedback extends Model
{
    use HasFactory;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'feedback';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'schema',
        'status',
        'created_by',
    ];

    /**
     * Check if the feedback form is published and open to respondents.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Check if the feedback form is in draft mode.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Scope query to published forms.
     *
     * @param  Builder<Feedback>  $query
     * @return Builder<Feedback>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Scope query to draft forms.
     *
     * @param  Builder<Feedback>  $query
     * @return Builder<Feedback>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'schema' => 'array',
    ];

    /**
     * Get the event associated with this feedback template.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FbEvent::class, 'event_id');
    }

    public function analyticsReports(): HasMany
    {
        return $this->hasMany(FeedbackAnalyticsReport::class);
    }

    public function publishedAnalyticsReport(): BelongsTo
    {
        return $this->belongsTo(FeedbackAnalyticsReport::class, 'published_analytics_report_id')
            ->where('status', 'published')->where('visibility', 'public');
    }

    /**
     * Get the submissions for this feedback template.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FbSubmission::class, 'feedback_id');
    }
}
