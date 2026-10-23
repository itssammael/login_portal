<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedbackAnalyticsReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['configuration'];

    protected $casts = [
        'configuration' => 'array',
        'published_configuration' => 'array',
        'published_at' => 'datetime',
        'unpublished_at' => 'datetime',
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }
}
