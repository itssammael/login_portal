<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class FbSubmission extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fb_submissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'feedback_id',
        'participant_id',
        'embed_id',
        'embed_session_id',
        'respondent_hash',
        'data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Get the feedback template associated with the submission.
     */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class, 'feedback_id');
    }

    /**
     * Get the participant associated with the submission.
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(FbParticipant::class, 'participant_id');
    }

    /**
     * Get the embed configuration associated with this submission if submitted via iframe.
     */
    public function embed(): BelongsTo
    {
        return $this->belongsTo(FeedbackEmbed::class, 'embed_id');
    }

    /**
     * Get the embed session associated with this submission.
     */
    public function embedSession(): BelongsTo
    {
        return $this->belongsTo(FeedbackEmbedSession::class, 'embed_session_id');
    }

    /**
     * Get the event associated with the submission through the feedback template.
     */
    public function event(): HasOneThrough
    {
        return $this->hasOneThrough(
            FbEvent::class,
            Feedback::class,
            'id', // Foreign key on feedback table...
            'id', // Foreign key on fb_events table...
            'feedback_id', // Local key on fb_submissions table...
            'event_id' // Local key on feedback table...
        );
    }
}
