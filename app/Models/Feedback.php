<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feedback extends Model
{
    use HasFactory;

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
    ];

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

    /**
     * Get the submissions for this feedback template.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FbSubmission::class, 'feedback_id');
    }
}
