<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FbFunction extends Model
{
    use HasFactory;

    protected $table = 'fb_functions';

    protected $fillable = [
        'function',
        'details',
        'created_by',
    ];

    /**
     * Events this function is assigned to.
     *
     * @return BelongsToMany<FbEvent, FbFunction>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(FbEvent::class, 'fb_event_function', 'function_id', 'event_id')
            ->withTimestamps();
    }

    /**
     * The administrator who created the function.
     *
     * @return BelongsTo<User, FbFunction>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Participants associated with this function.
     *
     * @return HasMany<FbParticipant, FbFunction>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(FbParticipant::class, 'function_id');
    }

    /**
     * Check if this function represents the 'Others' selection.
     */
    public function isOthers(): bool
    {
        return strcasecmp($this->function, 'Others') === 0 || strcasecmp($this->function, 'Other') === 0;
    }
}
