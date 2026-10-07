<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FbEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fb_events';

    protected $fillable = [
        'name',
        'details',
        'api_key',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (FbEvent $event): void {
            if (empty($event->api_key)) {
                $event->api_key = self::generateUniqueApiKey();
            }
        });

        static::created(function (FbEvent $event): void {
            if (! $event->embed()->exists()) {
                $event->embed()->create();
            }
        });
    }

    /**
     * Generate a unique API key for feedback events.
     */
    public static function generateUniqueApiKey(): string
    {
        do {
            $key = 'fb_'.Str::random(40);
        } while (static::withTrashed()->where('api_key', $key)->exists());

        return $key;
    }

    /**
     * The administrator who created the event.
     *
     * @return BelongsTo<User, FbEvent>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The configured feedback form for this event.
     *
     * @return HasOne<Feedback, FbEvent>
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(Feedback::class, 'event_id');
    }

    /**
     * The public embed configuration for this event.
     *
     * @return HasOne<FeedbackEmbed, FbEvent>
     */
    public function embed(): HasOne
    {
        return $this->hasOne(FeedbackEmbed::class, 'event_id');
    }

    /**
     * Functions / roles assigned to this event.
     *
     * @return BelongsToMany<FbFunction, FbEvent>
     */
    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(FbFunction::class, 'fb_event_function', 'event_id', 'function_id')
            ->withTimestamps();
    }
}
