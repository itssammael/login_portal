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
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'participant_name',
    ];

    /**
     * Get the resolved participant name if available from participant model or submission data.
     */
    public function getParticipantNameAttribute(): ?string
    {
        if (! empty($this->participant?->name) && is_string($this->participant->name)) {
            $name = trim($this->participant->name);
            if ($name !== '' && strtolower($name) !== 'anonymous') {
                return $name;
            }
        }

        if (! is_array($this->data)) {
            return null;
        }

        $fields = $this->feedback?->schema['fields'] ?? [];
        if (is_array($fields) && count($fields) > 0) {
            foreach ($fields as $field) {
                $type = $field['type'] ?? 'text';
                if (in_array($type, ['section', 'text_only'], true)) {
                    continue;
                }

                $fieldId = (string) ($field['id'] ?? '');
                $particular = strtolower(trim(strip_tags((string) ($field['particular'] ?? ''))));

                $isNameField = preg_match('/^(name|full\s*name|complete\s*name|participant(?:\'s)?\s*name|name\s*of\s*(?:the\s*)?participant|your\s*name|respondent(?:\'s)?\s*name|pangalan)(\s*[\(:].*|\s+optional)?$/i', $particular)
                    || preg_match('/^(name|full_name|fullname|participant_name|name_of_participant|respondent_name|your_name|complete_name)$/i', $fieldId);

                if ($isNameField && $fieldId !== '' && ! empty($this->data[$fieldId]) && is_string($this->data[$fieldId])) {
                    $val = trim($this->data[$fieldId]);
                    if ($val !== '' && strtolower($val) !== 'anonymous') {
                        return $val;
                    }
                }
            }
        }

        $candidateKeys = [
            'name',
            'full_name',
            'fullname',
            'participant_name',
            'name_of_participant',
            'respondent_name',
            'your_name',
            'complete_name',
            'pangalan',
        ];

        foreach ($candidateKeys as $key) {
            if (! empty($this->data[$key]) && is_string($this->data[$key])) {
                $val = trim($this->data[$key]);
                if ($val !== '' && strtolower($val) !== 'anonymous') {
                    return $val;
                }
            }
        }

        return null;
    }

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
