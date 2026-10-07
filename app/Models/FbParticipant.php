<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FbParticipant extends Model
{
    use HasFactory;

    protected $table = 'fb_participants';

    protected $fillable = [
        'name',
        'function_id',
        'custom_function',
        'agency',
        'designation',
        'years_in_designation',
        'location',
        'no_of_exercises',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'years_in_designation' => 'integer',
            'no_of_exercises' => 'integer',
        ];
    }

    /**
     * Determine if the participant submitted anonymously.
     */
    public function isAnonymous(): bool
    {
        return empty($this->name);
    }

    /**
     * Get display name, defaulting to Anonymous Participant if empty.
     */
    public function getDisplayNameAttribute(): string
    {
        return ! empty($this->name) ? $this->name : 'Anonymous Participant';
    }

    /**
     * The function/role assigned to this participant.
     *
     * @return BelongsTo<FbFunction, FbParticipant>
     */
    public function function(): BelongsTo
    {
        return $this->belongsTo(FbFunction::class, 'function_id');
    }

    /**
     * Submissions submitted by this participant.
     *
     * @return HasMany<FbSubmission, FbParticipant>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FbSubmission::class, 'participant_id');
    }
}
